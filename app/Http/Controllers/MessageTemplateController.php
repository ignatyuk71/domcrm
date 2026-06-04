<?php

namespace App\Http\Controllers;

use App\Models\MessageTemplate;
use Illuminate\Http\Request;

class MessageTemplateController extends Controller
{
    /**
     * Список шаблонів.
     */
    public function index()
    {
        $templates = MessageTemplate::orderBy('is_active', 'desc')
            ->orderBy('sort_order', 'desc')
            ->get();

        return view('templates.index', compact('templates'));
    }

    /**
     * API для чату: отримати список активних шаблонів.
     */
    public function apiList()
    {
        $templates = MessageTemplate::query()
            ->where('is_active', true)
            ->orderBy('sort_order', 'desc')
            ->orderBy('title', 'asc')
            ->get(['id', 'title', 'content']);

        return response()->json(['data' => $templates]);
    }

    /**
     * Форма створення.
     */
    public function create()
    {
        return view('templates.create');
    }

    /**
     * Збереження нового шаблону.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|max:255',
            'content' => 'required',
            'sort_order' => 'integer|min:0',
        ]);

        $validated['is_active'] = $request->has('is_active');

        MessageTemplate::create($validated);

        return redirect()->route('templates.index')->with('success', 'Шаблон створено!');
    }

    /**
     * Форма редагування.
     */
    public function edit(MessageTemplate $template)
    {
        return view('templates.edit', compact('template'));
    }

    /**
     * Оновлення шаблону.
     */
    public function update(Request $request, MessageTemplate $template)
    {
        $validated = $request->validate([
            'title' => 'required|max:255',
            'content' => 'required',
            'sort_order' => 'integer|min:0',
        ]);

        $validated['is_active'] = $request->has('is_active');

        $template->update($validated);

        return redirect()->route('templates.index')->with('success', 'Шаблон оновлено!');
    }

    /**
     * Видалення (фізичне).
     */
    public function destroy(MessageTemplate $template)
    {
        $template->delete();

        return redirect()->route('templates.index')->with('success', 'Шаблон видалено!');
    }
}
