<?php

namespace App\Modules\Files\Services;

use App\Models\User;
use App\Modules\Files\Models\StoredFile;
use App\Support\Settings\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Files on project servers in the RF (DEC-07): local disk in dev, S3-compatible storage on our VDS in prod.
 * Purposes define allowed types and visibility.
 */
class FileStorage
{
    /** @var array<string, array{mimes: list<string>, visibility: string, max_mb?: int}> */
    public const PURPOSES = [
        'avatar' => ['mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'visibility' => 'public', 'max_mb' => 10],
        'video_card' => ['mimes' => ['video/mp4', 'video/webm', 'video/quicktime'], 'visibility' => 'public', 'max_mb' => 100],
        'qualification' => ['mimes' => ['application/pdf', 'image/jpeg', 'image/png'], 'visibility' => 'private'],
        'attachment' => ['mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'visibility' => 'private'],
        'support' => ['mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain'], 'visibility' => 'private', 'max_mb' => 10],
        'recommendation' => ['mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'audio/mpeg', 'audio/mp4', 'text/plain', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'visibility' => 'private'],
        'kb_media' => ['mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'audio/mpeg', 'audio/mp4', 'video/mp4'], 'visibility' => 'private'],
        'article_image' => ['mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'visibility' => 'public', 'max_mb' => 10],
        'content_image' => ['mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'], 'visibility' => 'public', 'max_mb' => 10],
        'protocol' => ['mimes' => ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain'], 'visibility' => 'private'],
    ];

    public function store(UploadedFile $file, string $purpose, ?User $owner = null): StoredFile
    {
        $rules = self::PURPOSES[$purpose] ?? throw ValidationException::withMessages(['purpose' => 'Неизвестное назначение файла.']);
        $maxMb = $rules['max_mb'] ?? Settings::int('P-FILE-MAX-SIZE');
        $mime = $file->getMimeType() ?? 'application/octet-stream';

        if (! in_array($mime, $rules['mimes'], true)) {
            throw ValidationException::withMessages(['file' => 'Этот тип файла нельзя загрузить сюда.']);
        }
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => "Файл больше {$maxMb} МБ."]);
        }

        $visibility = $rules['visibility'];
        $disk = $visibility === 'public' ? config('filesystems.public_files_disk', 'public') : config('filesystems.private_files_disk', 'local');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs($purpose.'/'.now()->format('Y/m'), Str::uuid().'.'.$ext, ['disk' => $disk]);

        return StoredFile::create([
            'owner_id' => $owner?->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $mime,
            'size' => $file->getSize(),
            'visibility' => $visibility,
            'purpose' => $purpose,
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'scan_status' => 'skipped',
        ]);
    }
}
