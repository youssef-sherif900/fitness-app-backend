<?php

namespace App\Services\Storage;

use Aws\S3\S3Client;

/**
 * Signed URLs for direct-to-storage upload and short-lived playback (MEDIA-1).
 * URLs are signed against the PUBLIC endpoint, so a phone on the LAN can use them
 * (the API container itself talks to storage over the internal Docker network).
 */
class UploadSigner
{
    private function client(): S3Client
    {
        $d = config('filesystems.disks.s3');

        return new S3Client([
            'version' => 'latest',
            'region' => $d['region'] ?? 'us-east-1',
            'endpoint' => config('fitness.storage.public_endpoint') ?: ($d['endpoint'] ?? null),
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $d['key'], 'secret' => $d['secret']],
        ]);
    }

    /** @return array{url:string,method:string,headers:array<string,string>,expires_at:string} */
    public function put(string $key, string $mime): array
    {
        $ttl = config('fitness.storage.upload_ttl_seconds');
        $c = $this->client();
        $req = $c->createPresignedRequest(
            $c->getCommand('PutObject', ['Bucket' => config('filesystems.disks.s3.bucket'), 'Key' => $key, 'ContentType' => $mime]),
            "+{$ttl} seconds",
        );

        return [
            'url' => (string) $req->getUri(), 'method' => 'PUT', 'headers' => ['Content-Type' => $mime],
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
        ];
    }

    public function get(string $key): string
    {
        $ttl = config('fitness.storage.read_ttl_seconds');
        $c = $this->client();

        return (string) $c->createPresignedRequest(
            $c->getCommand('GetObject', ['Bucket' => config('filesystems.disks.s3.bucket'), 'Key' => $key]),
            "+{$ttl} seconds",
        )->getUri();
    }
}
