<?php

namespace App\Modules\Diary\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Audit;
use App\Modules\Diary\Models\DiaryEntry;
use App\Modules\Diary\Models\EmotionTag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** ADM-13: the fixed list of emotion tags for the diary. Admins edit the list only, never see entries. */
class EmotionTagController extends Controller
{
    public function index()
    {
        return response()->json(['data' => EmotionTag::ordered()->get()->map(fn (EmotionTag $t) => [...$t->toApi(), 'sort' => $t->sort, 'is_active' => $t->is_active])->values()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:64', Rule::unique('emotion_tags', 'title')],
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/', Rule::unique('emotion_tags', 'code')],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $code = $data['code'] ?? Str::slug(Str::ascii($data['title']));
        abort_if($code === '' || EmotionTag::where('code', $code)->exists(), 422, 'Укажите уникальный код метки.');

        $tag = EmotionTag::create([
            'title' => $data['title'], 'code' => $code,
            'sort' => $data['sort'] ?? ((int) EmotionTag::max('sort') + 1),
            'is_active' => $data['is_active'] ?? true,
        ]);
        Audit::log('ADM-13', 'diary.emotion_tag.created', $tag, ['title' => $tag->title]);

        return response()->json(['data' => [...$tag->toApi(), 'sort' => $tag->sort, 'is_active' => $tag->is_active]], 201);
    }

    public function update(Request $request, string $tag)
    {
        $model = EmotionTag::findOrFail($tag);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:64', Rule::unique('emotion_tags', 'title')->ignore($model->id)],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $model->update($data);
        Audit::log('ADM-13', 'diary.emotion_tag.updated', $model, $data);

        return response()->json(['data' => [...$model->toApi(), 'sort' => $model->sort, 'is_active' => $model->is_active]]);
    }

    /** A tag used in entries is hidden from the form instead of being deleted, so the history keeps it. */
    public function destroy(string $tag)
    {
        $model = EmotionTag::findOrFail($tag);
        $used = DiaryEntry::whereJsonContains('tag_ids', $model->id)->exists();
        if ($used) {
            $model->update(['is_active' => false]);
            Audit::log('ADM-13', 'diary.emotion_tag.deactivated', $model, ['title' => $model->title]);

            return response()->json(['ok' => true, 'deactivated' => true]);
        }
        Audit::log('ADM-13', 'diary.emotion_tag.deleted', $model, ['title' => $model->title]);
        $model->delete();

        return response()->json(['ok' => true, 'deactivated' => false]);
    }
}
