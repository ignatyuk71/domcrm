<?php

namespace App\Http\Controllers;

use App\Models\SavedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class SavedFileController extends Controller
{
    public function index()
    {
        $files = SavedFile::query()
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $files]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm|max:5120',
        ]);

        $file = $validated['file'];
        $datePath = now()->format('Y/m');
        $destinationPath = public_path("saved/{$datePath}");

        if (!is_dir($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }

        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $fileName = time().'_'.bin2hex(random_bytes(4)).'.'.strtolower($extension);
        $file->move($destinationPath, $fileName);
        @chmod($destinationPath.'/'.$fileName, 0644);

        $relativePath = "saved/{$datePath}/{$fileName}";
        $url = '/'.$relativePath;
        $type = str_starts_with((string) $file->getMimeType(), 'video') ? 'video' : 'image';

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
        $file = SavedFile::findOrFail($id);

        $fullPath = public_path($file->path);
        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }

        $file->delete();

        return response()->json(['success' => true]);
    }
}
