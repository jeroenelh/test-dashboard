<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ZipOrder /*implements ShouldQueue*/
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly int $orderId, public readonly string $productName)
    {
        //
    }

    /**
     * Execute the job.
     * @throws \Exception
     */
    public function handle(): void
    {
        ini_set('memory_limit', '3000M');

        Log::info('Start zip process: '.$this->orderId.' '.$this->productName);
        $this->downloadS3Files();
        $zipFileOnS3 = $this->uploadZipToS3($this->zipFiles());
        $tempUrl = $this->getTempUrl($zipFileOnS3);
        $this->cleanupLocalStorage();

        echo "\n\nMemory: ".(memory_get_peak_usage()/1024/1024)." MB \n\n";
        echo "\n\nTemp url: ".$tempUrl."\n\n";

    }

    /**
     * @throws \Exception
     */
    private function getDisk(): Filesystem
    {
        switch ($this->productName) {
            case 'photo':
                return Storage::disk('s3_photo');
            default:
                throw new \Exception('Invalid product name: '.$this->productName);
        }
    }

    /**
     * @throws \Exception
     */
    private function downloadS3Files(): void
    {
        $s3Files = $this->getDisk()->files('uploads/2025-05-15/'.$this->orderId);

        //Create folder
        if (!is_dir(storage_path('app/private/'.$this->orderId))) {
            mkdir(storage_path('app/private/' . $this->orderId));
        }

        foreach ($s3Files as $index => $s3File)
        {
            $localPath = storage_path('app/private/'.$this->orderId.'/'.basename($s3File));
            if (file_exists($localPath)) {
                continue;
            }

            // Skip zip download
            if (str_ends_with($s3File, 'zip')) {
                continue;
            }

            Log::info('Download file from S3 ('.($index+1).'/'.count($s3Files).'): '.$s3File);
            if (file_put_contents($localPath, $this->getDisk()->get($s3File)) === false) {
                throw new \Exception('Could not download file from S3 to local storage. '.$s3File);
            }
        }
    }

    /**
     * @return string Filepath of the zip
     * @throws \Exception
     */
    private function zipFiles(): string
    {
        // Create zip file
        $zip = new \ZipArchive();
        $zipFilePath = storage_path('app/private/'.$this->orderId.'/'.$this->orderId.'_'.$this->productName.'.zip');
        $zip->open($zipFilePath, \ZipArchive::CREATE);
        Log::info('Create zip file: '.$zipFilePath);

        // List files to zip
        $filesToZip = Storage::disk('local')->files($this->orderId);
        foreach ($filesToZip as $index => $file) {
            // Don't zip the zip file :)
            if (str_ends_with($file, 'zip')) {
                continue;
            }
            Log::info('Add file to zip ('.($index+1).'/'.count($filesToZip).'): '.basename($file));
            if ($zip->addFile(storage_path('app/private/'.$file), basename($file)) === false) {
                throw new \Exception('Could not add file to zip archive. '.$file);
            };
        }

        Log::info('Close zip file: '.$zipFilePath);
        $zip->close();

        return $zipFilePath;
    }

    /**
     * @param string $zipFilePath
     * @return string File path on S3
     * @throws \Exception
     */
    public function uploadZipToS3(string $zipFilePath): string
    {
        $this->getDisk()->put(
            'uploads/2025-05-15/'.$this->orderId.'/'.$this->orderId.'_'.$this->productName.'.zip',
            Storage::disk('local')->get($this->orderId.'/'.basename($zipFilePath))
        );

        return 'uploads/2025-05-15/'.$this->orderId.'/'.$this->orderId.'_'.$this->productName.'.zip';
    }

    public function cleanupLocalStorage(): void
    {
        $localFiles = Storage::disk('local')->files($this->orderId);
        foreach ($localFiles as $file) {
            Storage::disk('local')->delete($file);
            Log::info('Delete local file: '.$file);
        }

        Storage::disk('local')->deleteDirectory($this->orderId);
        Log::info('Delete local folder: '.$this->orderId);
    }

    private function getTempUrl($zipFileOnS3)
    {
        return $this->getDisk()->temporaryUrl($zipFileOnS3, now()->addDays(7));
    }
}
