<?php

// app/Http/Controllers/Api/ChatbotController.php
namespace App\Http\Controllers\Api;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use App\Models\ChatbotFlow;
use App\Models\ChatbotLead;
use App\Models\ChatbotMessage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatbotController extends Controller
{
    public function flow()
    {
        $flow = ChatbotFlow::where('is_active', true)->first();
        if (!$flow) {
            return response()->json(['data' => [
                'steps' => [
                    ['key'=>'greeting','question'=>"Hi! 👋 I'm Seben Assistant. Can I help you get started?",'placeholder'=>"Click 'Yes' to continue",'type'=>'button','is_button'=>true],
                    ['key'=>'name','question'=>"What's your name?",'placeholder'=>"Enter your name",'type'=>'text'],
                    ['key'=>'phone','question'=>"Great, {name}. What's your phone number?",'placeholder'=>"Enter your phone number",'type'=>'phone'],
                    ['key'=>'email','question'=>"Lastly, your email?",'placeholder'=>"Enter your email address",'type'=>'email'],
                ]
            ]]);
        }
        return response()->json(['data' => ['steps' => $flow->steps]]);
    }

    public function saveLead(Request $req)
    {
        // payload: { lead_id?, field?, value?, completed?:bool, answers?:{}, messages?:[] }
        $lead = null;
        if ($req->lead_id) {
            $lead = ChatbotLead::find($req->lead_id);
        }
        if (!$lead) {
            $lead = ChatbotLead::create([
                'status' => 'in_progress',
                'meta' => [
                    'ip' => $req->ip(),
                    'ua' => (string) $req->userAgent(),
                    'utm' => $req->get('utm', []),
                ],
            ]);
        }

        // partial step update
        if ($req->filled('field')) {
            $field = $req->string('field')->toString();
            $value = $req->string('value')->toString();

            // validations per field type (simple)
            if ($field === 'email') {
                $req->validate(['value' => 'email']);
            } elseif ($field === 'phone') {
                $req->validate(['value' => ['regex:/^[6-9]\d{9}$/']]); // Indian mobile pattern
            }

            $answers = $lead->answers ?? [];
            $answers[$field] = $value;
            $lead->answers = $answers;

            // mirror into columns for convenience
            if (in_array($field, ['name','phone','email'])) {
                $lead->{$field} = $value;
            }
            $lead->save();

            // log message
            ChatbotMessage::create([
                'lead_id' => $lead->id,
                'direction' => 'user',
                'content' => $value,
                'step_key' => $field,
                'ip' => $req->ip(),
                'ua' => (string)$req->userAgent(),
            ]);
        }

        // final submit
        if ($req->boolean('completed')) {
            $lead->status = 'submitted';
            // optional full answers overwrite
            if (is_array($req->answers)) {
                $lead->answers = array_merge($lead->answers ?? [], $req->answers);
                foreach (['name','phone','email'] as $k) {
                    if (!empty($lead->answers[$k])) $lead->{$k} = $lead->answers[$k];
                }
            }
            $lead->save();

            // log transcript if provided
            if (is_array($req->messages)) {
                foreach ($req->messages as $m) {
                    ChatbotMessage::create([
                        'lead_id' => $lead->id,
                        'direction' => $m['type'] === 'user' ? 'user' : 'bot',
                        'content' => (string)($m['content'] ?? ''),
                        'step_key' => $m['step_key'] ?? null,
                        'ip' => $req->ip(),
                        'ua' => (string)$req->userAgent(),
                    ]);
                }
            }
        }

        return response()->json(['data' => [
            'lead_id' => $lead->id,
            'status' => $lead->status,
            'answers' => $lead->answers,
        ]]);
    }


    public function leads(Request $req)
{
    // filters
    $q        = trim((string) $req->get('q', ''));
    $status   = $req->get('status');                   // in_progress | submitted
    $from     = $req->get('date_from');                // YYYY-MM-DD
    $to       = $req->get('date_to');                  // YYYY-MM-DD
    $perPage  = min(100, max(5, (int) $req->get('per_page', 20)));
    $sort     = $req->get('sort', '-id');              // -id | -created_at | name | email ...

    $qBuilder = ChatbotLead::query()
        ->withCount('messages')
        ->select(['id','name','phone','email','status','answers','created_at','updated_at']);

    if ($status && in_array($status, ['in_progress','submitted'])) {
        $qBuilder->where('status', $status);
    }

    if ($q !== '') {
        $qBuilder->where(function($w) use ($q) {
            $w->where('name', 'like', "%{$q}%")
              ->orWhere('email', 'like', "%{$q}%")
              ->orWhere('phone', 'like', "%{$q}%")
              ->orWhere('answers', 'like', "%{$q}%");
        });
    }

    if ($from) $qBuilder->whereDate('created_at', '>=', Carbon::parse($from)->toDateString());
    if ($to)   $qBuilder->whereDate('created_at', '<=', Carbon::parse($to)->toDateString());

    // sorting
    if ($sort === '-created_at') $qBuilder->orderByDesc('created_at');
    elseif ($sort === 'created_at') $qBuilder->orderBy('created_at');
    elseif ($sort === 'name') $qBuilder->orderBy('name')->orderByDesc('id');
    else $qBuilder->orderByDesc('id'); // default

    $rows = $qBuilder->paginate($perPage);

    // shape response
    $rows->getCollection()->transform(function($l) {
        return [
            'id'         => $l->id,
            'name'       => $l->name,
            'phone'      => $l->phone,
            'email'      => $l->email,
            'status'     => $l->status,
            'answers'    => $l->answers,
            'messages_count' => $l->messages_count,
            'created_at' => $l->created_at?->toIso8601String(),
            'updated_at' => $l->updated_at?->toIso8601String(),
        ];
    });

    return response()->json($rows);
}

public function showLead(Request $req, ChatbotLead $lead)
{
    $include = explode(',', (string)$req->get('include', ''));
    $lead->loadCount('messages');

    $out = [
        'id'         => $lead->id,
        'name'       => $lead->name,
        'phone'      => $lead->phone,
        'email'      => $lead->email,
        'status'     => $lead->status,
        'answers'    => $lead->answers,
        'meta'       => $lead->meta,
        'messages_count' => $lead->messages_count,
        'created_at' => $lead->created_at?->toIso8601String(),
        'updated_at' => $lead->updated_at?->toIso8601String(),
    ];

    if (in_array('messages', $include)) {
        $msgs = ChatbotMessage::where('lead_id', $lead->id)
            ->orderBy('created_at')->get(['id','direction','content','step_key','created_at']);
        $out['messages'] = $msgs->map(fn($m) => [
            'id'=>$m->id,
            'type'=>$m->direction,
            'content'=>$m->content,
            'step_key'=>$m->step_key,
            'created_at'=>$m->created_at?->toIso8601String(),
        ]);
    }

    return response()->json(['data' => $out]);
}
}
