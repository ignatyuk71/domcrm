<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\ExpenseReceipt;
use App\Services\Expenses\ExpenseData;
use App\Services\Expenses\ReceiptFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpenseReceiptController extends Controller
{
    public function store(Request $request, Expense $expense, ExpensePayment $payment)
    {
        abort_unless($payment->expense_id === $expense->id, 404);
        $request->validate(['files' => ['required', 'array', 'min:1', 'max:10'], 'files.*' => ['required', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'extensions:pdf,jpg,jpeg,png,webp']], [
            'files.required' => 'Виберіть квитанції.', 'files.max' => 'Можна додати не більше 10 квитанцій.',
            'files.*.max' => 'Квитанція не може бути більшою за 10 МБ.', 'files.*.mimetypes' => 'Дозволені тільки PDF, JPEG, PNG та WebP.',
            'files.*.extensions' => 'Дозволені тільки PDF, JPEG, PNG та WebP.', 'files.*.file' => 'Файл не вдалося завантажити.',
        ]);
        $saved = [];
        try {
            $expense = DB::transaction(function () use ($expense, $payment, $request, &$saved) {
                $locked = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
                $current = $locked->payments()->whereKey($payment->id)->firstOrFail();
                $knownHashes = $current->receipts()->pluck('content_hash')->filter()->all();
                $files = collect($request->file('files'))->unique(fn ($file) => hash_file('sha256', $file->getRealPath()))
                    ->reject(fn ($file) => in_array(hash_file('sha256', $file->getRealPath()), $knownHashes, true));
                if ($current->receipts()->count() + $files->count() > 10) {
                    throw ValidationException::withMessages(['files' => 'Для однієї оплати дозволено максимум 10 квитанцій.']);
                }
                foreach ($files as $file) {
                    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
                    $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
                    if (! $extension) {
                        throw ValidationException::withMessages(['files' => 'Непідтримуваний вміст файлу.']);
                    }
                    $path = 'expenses/receipts/'.Str::uuid().'.'.$extension;
                    $saved[] = $path;
                    $stream = fopen($file->getRealPath(), 'rb');
                    try {
                        $stored = Storage::disk('local')->put($path, $stream, ['visibility' => 'private']);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    if (! $stored || ! Storage::disk('local')->exists($path)) {
                        throw new \RuntimeException('Не вдалося зберегти квитанцію. Спробуйте ще раз.');
                    }
                    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName())));
                    $current->receipts()->create(['path' => $path, 'content_hash' => hash_file('sha256', $file->getRealPath()), 'original_name' => mb_substr($name ?: 'Квитанція.'.$extension, 0, 240), 'mime_type' => $mime, 'size' => $file->getSize(), 'created_by' => $request->user()->id]);
                }
                if ($files->isNotEmpty()) {
                    $locked->increment('version');
                }

                return $locked;
            });
        } catch (\Throwable $error) {
            // Компенсація записів на диску, якщо DB-транзакцію відкочено.
            foreach ($saved as $path) {
                try {
                    Storage::disk('local')->delete($path);
                } catch (\Throwable $cleanup) {
                    report($cleanup);
                }
            }
            throw $error;
        }

        return response()->json(['data' => ExpenseData::detail($expense)], 201);
    }

    public function show(Request $request, ExpenseReceipt $receipt)
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($receipt->path), 404, 'Квитанцію не знайдено.');
        $headers = ['Content-Type' => $receipt->mime_type, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'X-Frame-Options' => 'SAMEORIGIN'];

        return $disk->response($receipt->path, $receipt->original_name, $headers, $request->boolean('download') ? 'attachment' : 'inline');
    }

    public function destroy(ExpenseReceipt $receipt)
    {
        DB::transaction(function () use ($receipt) {
            $payment = ExpensePayment::findOrFail($receipt->payment_id);
            $expense = Expense::whereKey($payment->expense_id)->lockForUpdate()->firstOrFail();
            $current = ExpenseReceipt::findOrFail($receipt->id);
            $path = $current->path;
            $current->delete();
            $expense->increment('version');
            ReceiptFiles::deleteAfterCommit([$path]);
        }, 3);

        return response()->json(['ok' => true]);
    }
}
