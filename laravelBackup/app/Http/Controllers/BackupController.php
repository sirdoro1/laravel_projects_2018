<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Events\StatementPrepared; // set the fetch mode
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use DB;
use App\Post;
use App\User;
use App\Jobs\BackupTableJob;
use Artisan;

date_default_timezone_set('Europe/Samara');

class BackupController extends Controller
{
    const MAX_VALUE = 200000; //kilobytes 
    // const MAX_VALUE = 9; //kilobytes 

    public function lockTable()
    {
        // lock all tables
        DB::unprepared('FLUSH TABLES WITH READ LOCK;');
    }

    public function setQueueJob($job1, $job2)
    {
        BackupTableJob::dispatch($job1, $job2)
            ->delay(Carbon::now()
            ->addSeconds(2));
        // Artisan::call('queue:work');     
    }

    public function unlockTable()
    {
        // unlock all tables
        DB::unprepared('UNLOCK TABLES');
    }

    public function queryFetch($data)
    {
        $pdo  = DB::connection()->getPdo();
        $stmt = $pdo->prepare($data);
        $stmt->execute();
        // $stmt = $pdo->query($data);
        $results = $stmt->fetch();
        return $results;
    }

    public function create(Request $request)
    {
    	$this->setPdoMode();
        $numTables = DB::select("SHOW TABLES");
        $countUserRecords = User::count();
        $countPostRecords = Post::count();
 		
        return view('backup', compact('numTables','countUserRecords', 'countPostRecords'));
    }

    public function setPdoMode()
    {
    	\Event::listen(StatementPrepared::class, function($event) {
        $event->statement->setFetchMode(\PDO::FETCH_ASSOC);});
    }

    // public function backup(Request $request, User $user, Post $post)
    public function backup(Request $request, User $user, Post $post)
    {
        $this->setQueueJob($user, $post);            
    	if ($request->all()) {
    		$tables = request('table');
    		$output = '';     
        	foreach ($tables as $key => $table) {  
                $this->lockTable();
    			$show_table_query = $this->queryFetch("SHOW CREATE TABLE {$table}");
    			$output .="\n" . $show_table_query[1] . ";\n";
                $this->setPdoMode();
        		$single_result = DB::select("SELECT * FROM {$table}");
                $output .= $this->getTableData($single_result, $table);
            }
            if ($this->checkFileSize($output)) {
                return redirect()->route('create'); 
            }                  
	    }             
            return redirect()->route('backupError'); 
    }

    // Stores the file in this location: storage/app
    public function download($output)
    {
        $dt = Carbon::now();
        $file_name = 'backup_on[' . $dt->format('y-m-d H-i-s') . '].sql';
        Storage::disk('local')->put($file_name, $output);
    }

    public function getTableData($single_result, $table) 
    {
        $this->unlockTable();
        $output = '';
        foreach ($single_result as $key => $table_val) {  
            $output .= "\nINSERT INTO $table("; 
            $output .= "" .addslashes(implode(", ", array_keys($table_val))) . ") VALUES(";
            $output .= "'" . addslashes(implode("','", array_values($table_val))) . "');\n";
        }
        $output = $this->cacheData($table, $output);   
        return $output;
    }

    public function checkFileSize($file)
    {
        $file_size = strlen($file);
        // convert bytes to kilobytes 
        $file_size = round($file_size / 1024, 0, PHP_ROUND_HALF_UP);
        if ($file_size <= self::MAX_VALUE) {
            $this->download($file);
            return true;
         } 
        return false;               
    }

    public function cacheData($table, $data)
    {
        // $table = $table;
        $start = microtime(true);
        $data = Cache::remember('table', 10, function() use ($table){
            return DB::table($table)->get();
        });
        $duration = (microtime(true) -$start) * 1000;

        \Log::info("From cache: " . $duration .' ms');
        return $data;
    }
}
