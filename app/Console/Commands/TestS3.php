<?php

namespace App\Console\Commands;

use App\Jobs\ZipOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestS3 extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-s3';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        ZipOrder::dispatch(1554077, 'photo');


        exit();

        $orderId = 1554077;
        $files = Storage::disk('s3_photo')->files('uploads/2025-05-15/'.$orderId);
//        foreach ($files as $file) {
//            $filename = basename($file);
//            $test = Storage::disk('s3_photo')->get($file);
//            Storage::disk('local')->put($orderId.'/'.$filename, $test);
//        }

        $zip = new \ZipArchive();
        $zip->open(storage_path('app/private/'.$orderId.'/test.zip'), \ZipArchive::CREATE);

        $filesToZip = Storage::disk('local')->files($orderId);
        foreach ($filesToZip as $file) {
            $zip->addFile(storage_path('app/private/'.$file), basename($file));
        }
        $zip->close();
    }
}
