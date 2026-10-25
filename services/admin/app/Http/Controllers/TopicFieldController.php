<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\Topic;
use EasyLog\Models\TopicField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 字段索引 CRUD（控制台维护字段类型 → 存储 mapping）
 *
 * 路由：
 * - GET    /topics/{topic}/fields       index
 * - POST   /topics/{topic}/fields       store
 * - GET    /topics/{topic}/fields/{id}  show
 * - PUT    /topics/{topic}/fields/{id}  update
 * - DELETE /topics/{topic}/fields/{id}  destroy
 *
 * 多租户隔离：topic → workspace → tenant 三层限定。
 */
class TopicFieldController extends Controller
{
    public function __construct()
    {
        $this->middleware('ability:topics.view')->only(['index', 'show']);
        $this->middleware('ability:topics.update')->only(['store', 'update', 'destroy']);
    }
    public function index(string $topic): JsonResponse
    {
        $topicModel = $this->resolveTopic($topic);
        $items = TopicField::where('topic_id', $topicModel->id)->orderBy('id')->get();

        return response()->json([
            'data' => $items->map(fn (TopicField $f) => $this->serialize($f)),
        ]);
    }

    public function store(Request $request, string $topic): JsonResponse
    {
        $topicModel = $this->resolveTopic($topic);
        $validated = $request->validate([
            'field_name' => 'required|string|max:64',
            'field_type' => 'required|in:text,keyword,long,double,date,ip,geo',
            'indexed'    => 'nullable|boolean',
            'analyzer'   => 'nullable|string|max:32',
        ]);

        // unique(topic_id, field_name)：显式检查，保证 SQLite/MySQL 下统一返回 422
        $exists = TopicField::where('topic_id', $topicModel->id)
            ->where('field_name', $validated['field_name'])
            ->exists();
        if ($exists) {
            abort(422, "Field '{$validated['field_name']}' already exists");
        }

        $field = new TopicField();
        $field->topic_id   = $topicModel->id;
        $field->field_name = $validated['field_name'];
        $field->field_type = $validated['field_type'];
        $field->indexed    = (bool) ($validated['indexed'] ?? true);
        $field->analyzer   = $validated['analyzer'] ?? 'standard';
        $field->save();

        return response()->json($this->serialize($field), 201);
    }

    public function show(string $topic, int $field): JsonResponse
    {
        $topicModel = $this->resolveTopic($topic);
        return response()->json($this->serialize($this->loadField($topicModel, $field)));
    }

    public function update(Request $request, string $topic, int $field): JsonResponse
    {
        $topicModel = $this->resolveTopic($topic);
        $model = $this->loadField($topicModel, $field);

        $validated = $request->validate([
            'field_type' => 'sometimes|in:text,keyword,long,double,date,ip,geo',
            'indexed'    => 'sometimes|boolean',
            'analyzer'   => 'sometimes|string|max:32',
        ]);

        $model->fill($validated);
        $model->save();

        return response()->json($this->serialize($model));
    }

    public function destroy(string $topic, int $field): JsonResponse
    {
        $topicModel = $this->resolveTopic($topic);
        $model = $this->loadField($topicModel, $field);
        $model->delete();

        return response()->json(null, 204);
    }

    private function resolveTopic(string $topicUuid): Topic
    {
        $tenantId = app('easylog.tenant_id');
        $topic = Topic::with('workspace')
            ->where('uuid', $topicUuid)
            ->whereHas('workspace', function ($q) use ($tenantId) {
                $q->where('status', 'active')
                    ->when($tenantId, fn ($w) => $w->where('tenant_id', $tenantId));
            })
            ->first();
        if (!$topic) {
            abort(404, 'Topic not found');
        }
        return $topic;
    }

    private function loadField(Topic $topic, int $fieldId): TopicField
    {
        $field = TopicField::where('id', $fieldId)
            ->where('topic_id', $topic->id)
            ->first();
        if (!$field) {
            abort(404, 'Field not found');
        }
        return $field;
    }

    private function serialize(TopicField $f): array
    {
        return [
            'id'         => $f->id,
            'field_name' => $f->field_name,
            'field_type' => $f->field_type,
            'indexed'    => (bool) $f->indexed,
            'analyzer'   => $f->analyzer,
            'created_at' => $f->created_at?->toISOString(),
            'updated_at' => $f->updated_at?->toISOString(),
        ];
    }
}
