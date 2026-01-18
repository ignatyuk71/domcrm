<?php

namespace App\Http\Controllers;

use App\Models\SavedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class SavedFileController extends Controller
{
    public function index()
    {
        if (!Schema::hasTable('saved_files')) {
            return response()->json(['error' => 'saved_files table missing'], 500);
        }

        $files = SavedFile::query()
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $files]);
    }

    public function store(Request $request)
    {
        if (!Schema::hasTable('saved_files')) {
            return response()->json(['error' => 'saved_files table missing'], 500);
        }

        $validated = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm|max:5120',
        ]);

        $file = $validated['file'];
        $datePath = now()->format('Y/m');
        $destinationPath = public_path("saved/{$datePath}");

        if (!is_dir($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }

        $mimeType = (string) $file->getClientMimeType();
        $type = str_starts_with($mimeType, 'video') ? 'video' : 'image';
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $fileName = time().'_'.bin2hex(random_bytes(4)).'.'.strtolower($extension);
        $file->move($destinationPath, $fileName);
        @chmod($destinationPath.'/'.$fileName, 0644);

        $relativePath = "saved/{$datePath}/{$fileName}";
        $url = '/'.$relativePath;
        $saved = SavedFile::create([
            'filename' => $file->getClientOriginalName(),
            'path' => $relativePath,
            'url' => $url,
            'type' => $type,
        ]);

        return response()->json(['data' => $saved], 201);
    }

    public function destroy($id)
    {
        if (!Schema::hasTable('saved_files')) {
            return response()->json(['error' => 'saved_files table missing'], 500);
        }

        $file = SavedFile::findOrFail($id);

        $fullPath = public_path($file->path);
        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }

        $file->delete();

        return response()->json(['success' => true]);
    }
}
