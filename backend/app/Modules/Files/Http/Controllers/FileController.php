<?php

namespace App\Modules\Files\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Files\Models\StoredFile;
use App\Modules\Files\Services\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FileController extends Controller
{
    public function upload(Request $request, FileStorage $storage)
    {
        $data = $request->validate([
            'file' => ['required', 'file'],
            'purpose' => ['required', Rule::in(array_keys(FileStorage::PURPOSES))],
        ]);
        $file = $storage->store($data['file'], $data['purpose'], $request->user());

        return response()->json(['data' => $file->toApi()], 201);
    }

    /** Signed temporary link; the signature is the access check (issued only to users allowed to see the file). */
    public function download(StoredFile $file)
    {
        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Content-Type' => $file->mime_type]);
    }
}
