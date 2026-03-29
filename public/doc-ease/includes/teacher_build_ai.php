<?php
// Teacher AI Build Assistant helpers.
// Purpose:
// - Evaluate context readiness before suggesting a build
// - Generate a structured build draft (categories + components + weights)

require_once __DIR__ . '/env_secrets.php';

if (!function_exists('tgc_ai_read_api_key')) {
    function tgc_ai_read_api_key($envName, $path, $startsWith = '') {
        $env = function_exists('doc_ease_env_value')
            ? trim((string) doc_ease_env_value((string) $envName))
            : trim((string) getenv((string) $envName));
        if ($env === '') return '';
        if ($startsWith !== '' && strpos($env, $startsWith) !== 0) return '';
        return $env;
    }
}

if (!function_exists('tgc_ai_openai_api_key')) {
    function tgc_ai_openai_api_key() {
        return tgc_ai_read_api_key('OPENAI_API_KEY', '', 'sk-');
    }
}

if (!function_exists('tgc_ai_extract_json_object')) {
    function tgc_ai_extract_json_object($content) {
        $content = trim((string) $content);
        if ($content === '') return null;

        $decoded = json_decode($content, true);
        if (is_array($decoded)) return $decoded;

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $content, $m)) {
            $decoded = json_decode((string) ($m[1] ?? ''), true);
            if (is_array($decoded)) return $decoded;
        }

        $a = strpos($content, '{');
        $b = strrpos($content, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $decoded = json_decode((string) substr($content, $a, $b - $a + 1), true);
            if (is_array($decoded)) return $decoded;
        }

        return null;
    }
}

if (!function_exists('tgc_ai_clean_text')) {
    function tgc_ai_clean_text($value, $maxLen = 1000) {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', ' ', $value);
        if (!is_string($value)) $value = '';
        if ($maxLen > 0 && strlen($value) > $maxLen) {
            $value = trim((string) substr($value, 0, $maxLen));
        }
        return $value;
    }
}

if (!function_exists('tgc_ai_list_from_lines')) {
    function tgc_ai_list_from_lines($value, $maxItems = 8, $maxLen = 140) {
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $parts = preg_split('/[\n,;]+/', $value);
        if (!is_array($parts)) return [];

        $maxItems = max(1, (int) $maxItems);
        $out = [];
        $seen = [];
        foreach ($parts as $p) {
            $item = trim((string) $p);
            $item = preg_replace('/^\s*(?:[-*]+|\d+[.)])\s*/', '', (string) $item);
            $item = tgc_ai_clean_text($item, $maxLen);
            if ($item === '') continue;
            $k = strtolower($item);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $item;
            if (count($out) >= $maxItems) break;
        }
        return $out;
    }
}

if (!function_exists('tgc_ai_has_ryhn_intro_prefix')) {
    function tgc_ai_has_ryhn_intro_prefix($message) {
        $message = trim((string) $message);
        if ($message === '') return false;
        return (bool) preg_match('/^hi,\s*i\s*(?:\'m|am)\s*ryhn\b[:.!-]?\s*/i', $message);
    }
}

if (!function_exists('tgc_ai_strip_ryhn_intro_prefix')) {
    function tgc_ai_strip_ryhn_intro_prefix($message) {
        $message = trim((string) $message);
        if ($message === '') return '';
        $stripped = preg_replace('/^hi,\s*i\s*(?:\'m|am)\s*ryhn\b[:.!-]?\s*/i', '', $message, 1);
        if (!is_string($stripped)) $stripped = '';
        return trim($stripped);
    }
}

if (!function_exists('tgc_ai_history_has_assistant_reply')) {
    function tgc_ai_history_has_assistant_reply($history) {
        if (!is_array($history)) return false;
        foreach ($history as $row) {
            if (!is_array($row)) continue;
            $role = strtolower(trim((string) ($row['role'] ?? '')));
            if (!in_array($role, ['assistant', 'ai'], true)) continue;
            $content = trim((string) ($row['content'] ?? ''));
            if ($content !== '') return true;
        }
        return false;
    }
}

if (!function_exists('tgc_ai_with_ryhn_intro')) {
    function tgc_ai_with_ryhn_intro($message, $history = null, $introOnce = false) {
        $message = trim((string) $message);
        if ($message === '') $message = 'Please share one more detail.';

        $prefixed = tgc_ai_has_ryhn_intro_prefix($message) ? $message : ("Hi, I'm Ryhn. " . $message);
        if (!$introOnce) return $prefixed;

        if (!tgc_ai_history_has_assistant_reply($history)) return $prefixed;

        $withoutIntro = tgc_ai_strip_ryhn_intro_prefix($prefixed);
        return $withoutIntro !== '' ? $withoutIntro : 'Please share one more detail.';
    }
}

if (!function_exists('tgc_ai_contains_sensitive_access_intent')) {
    function tgc_ai_contains_sensitive_access_intent($value) {
        $text = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));
        if ($text === '') return false;

        $verbs = '(?:show|give|reveal|expose|dump|extract|read|list|display|send|share|access|open|provide|leak|steal|bypass|hack|crack|fetch|pull)';
        $targets = '(?:database|\bdb\b|schema|users?\s*table|credentials?|passwords?|api\s*keys?|tokens?|secrets?|server|filesystem|source\s*code|\.env|phpmyadmin|mysql)';

        if (preg_match('/\b' . $verbs . '\b[^\n\r]{0,90}\b' . $targets . '\b/i', $text)) return true;
        if (preg_match('/\b' . $targets . '\b[^\n\r]{0,90}\b' . $verbs . '\b/i', $text)) return true;
        return false;
    }
}

if (!function_exists('tgc_ai_chat_topic_keywords')) {
    function tgc_ai_chat_topic_keywords(array $context) {
        $keywords = [
            'grade', 'grading', 'assessment', 'assessments', 'component', 'components',
            'category', 'categories', 'weight', 'weights', 'weighted', 'percent', 'percentage',
            'rubric', 'score', 'scores', 'quiz', 'quizzes', 'exam', 'exams',
            'assignment', 'assignments', 'project', 'projects', 'performance', 'participation',
            'attendance', 'midterm', 'final', 'term', 'semester', 'class', 'class record',
            'section', 'subject', 'outcome', 'outcomes', 'learning', 'policy',
            'integrity', 'constraint', 'constraints', 'build', 'draft', 'template',
            'distribution', 'split',
        ];

        $subjectCode = tgc_ai_clean_text((string) ($context['subject_code'] ?? ''), 60);
        $subjectName = tgc_ai_clean_text((string) ($context['subject_name'] ?? ''), 160);
        if ($subjectCode !== '') {
            $keywords[] = strtolower($subjectCode);
            $keywords[] = strtolower(str_replace(' ', '', $subjectCode));
        }

        $stop = [
            'and' => true, 'the' => true, 'for' => true, 'with' => true, 'from' => true,
            'this' => true, 'that' => true, 'class' => true, 'subject' => true, 'course' => true,
            'year' => true, 'term' => true, 'semester' => true, 'section' => true, 'records' => true,
        ];
        foreach (preg_split('/[^a-z0-9]+/i', strtolower($subjectName)) as $part) {
            $part = trim((string) $part);
            if ($part === '' || strlen($part) < 3) continue;
            if (isset($stop[$part])) continue;
            $keywords[] = $part;
        }

        $seen = [];
        $out = [];
        foreach ($keywords as $kw) {
            $kw = strtolower(trim((string) $kw));
            if ($kw === '') continue;
            if (isset($seen[$kw])) continue;
            $seen[$kw] = true;
            $out[] = $kw;
        }
        return $out;
    }
}

if (!function_exists('tgc_ai_is_short_follow_up_message')) {
    function tgc_ai_is_short_follow_up_message($message) {
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) $message));
        if ($clean === '') return false;
        if (strlen($clean) > 120) return false;

        $wordCount = 0;
        if (preg_match_all('/[a-z0-9%.\-]+/i', $clean, $m)) {
            $wordCount = count($m[0] ?? []);
        }
        return $wordCount > 0 && $wordCount <= 18;
    }
}

if (!function_exists('tgc_ai_validate_teacher_topic_message')) {
    function tgc_ai_validate_teacher_topic_message(array $context, array $history, $teacherMessage) {
        $teacherMessage = tgc_ai_clean_text($teacherMessage, 1800);
        if ($teacherMessage === '') return [false, 'Message is empty.'];

        if (tgc_ai_contains_sensitive_access_intent($teacherMessage)) {
            return [false, 'Message blocked: AI is limited to class-record grading topics and cannot assist with database/system access, credentials, or internal secrets.'];
        }

        $lowerMessage = strtolower($teacherMessage);
        $keywords = tgc_ai_chat_topic_keywords($context);
        foreach ($keywords as $kw) {
            if ($kw === '') continue;
            if (strpos($lowerMessage, $kw) !== false) return [true, ''];
        }

        $hasHistory = false;
        foreach ($history as $row) {
            if (!is_array($row)) continue;
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') continue;
            $hasHistory = true;
            break;
        }
        if ($hasHistory && tgc_ai_is_short_follow_up_message($teacherMessage)) {
            return [true, ''];
        }

        return [false, 'Message is outside topic. Ask about this class record only (components, weights, assessment strategy, grading policy, or term setup).'];
    }
}

if (!function_exists('tgc_ai_local_readiness')) {
    function tgc_ai_local_readiness(array $answers) {
        $rules = [
            ['field' => 'learning_objectives', 'label' => 'Learning outcomes/objectives', 'min' => 35, 'weight' => 22],
            ['field' => 'evidence_of_learning', 'label' => 'Evidence of learning', 'min' => 35, 'weight' => 22],
            ['field' => 'assessment_strategy', 'label' => 'Assessment strategy', 'min' => 35, 'weight' => 20],
            ['field' => 'fairness_and_integrity', 'label' => 'Fairness/integrity policy', 'min' => 30, 'weight' => 18],
            ['field' => 'class_constraints', 'label' => 'Class constraints and realities', 'min' => 25, 'weight' => 18],
        ];

        $score = 0;
        $maxScore = 0;
        $gaps = [];
        foreach ($rules as $r) {
            $maxScore += (int) $r['weight'];
            $v = trim((string) ($answers[(string) $r['field']] ?? ''));
            if (strlen($v) >= (int) $r['min']) {
                $score += (int) $r['weight'];
            } else {
                $gaps[] = (string) $r['label'] . ': add more specific detail.';
            }
        }

        $mustHave = tgc_ai_list_from_lines((string) ($answers['must_have_components'] ?? ''), 10, 120);
        if (count($mustHave) >= 2) {
            $score += 10;
            $maxScore += 10;
        } else {
            $maxScore += 10;
            $gaps[] = 'Must-have components: list at least 2 concrete components.';
        }

        $readinessScore = $maxScore > 0 ? (int) round(($score / $maxScore) * 100) : 0;
        if ($readinessScore < 0) $readinessScore = 0;
        if ($readinessScore > 100) $readinessScore = 100;

        $ready = ($readinessScore >= 70 && count($gaps) <= 2);
        $followUps = [];
        foreach ($gaps as $g) {
            $q = $g;
            if (stripos($q, ':') !== false) {
                $q = trim((string) substr($q, 0, strpos($q, ':')));
            }
            if ($q !== '') $followUps[] = 'Please clarify: ' . $q . '.';
            if (count($followUps) >= 5) break;
        }

        return [
            'ready' => $ready,
            'readiness_score' => $readinessScore,
            'summary' => $ready
                ? 'Base readiness check passed. AI can proceed to draft a build.'
                : 'Need stronger teaching context before generating a build.',
            'knowledge_gaps' => array_slice($gaps, 0, 6),
            'follow_up_questions' => array_slice($followUps, 0, 5),
        ];
    }
}

if (!function_exists('tgc_ai_redistribute_weights')) {
    function tgc_ai_redistribute_weights(array $components, $targetWeight) {
        $targetWeight = (float) $targetWeight;
        if ($targetWeight <= 0) $targetWeight = 100.0;
        if (count($components) === 0) return [];

        $totalRaw = 0.0;
        foreach ($components as $c) {
            $totalRaw += max(0.0, (float) ($c['raw_weight'] ?? 0));
        }
        if ($totalRaw <= 0.0) return [];

        $out = [];
        foreach ($components as $i => $c) {
            $raw = max(0.0, (float) ($c['raw_weight'] ?? 0));
            $scaled = ($raw / $totalRaw) * $targetWeight;
            $out[$i] = $c;
            $out[$i]['weight'] = round($scaled, 2);
        }

        $sum = 0.0;
        foreach ($out as $c) $sum += (float) ($c['weight'] ?? 0);
        $delta = round($targetWeight - $sum, 2);
        if (abs($delta) >= 0.01) {
            $idx = 0;
            $maxW = -1.0;
            foreach ($out as $i => $c) {
                $w = (float) ($c['weight'] ?? 0);
                if ($w > $maxW) {
                    $maxW = $w;
                    $idx = (int) $i;
                }
            }
            $newW = round(((float) ($out[$idx]['weight'] ?? 0)) + $delta, 2);
            if ($newW <= 0) $newW = 0.01;
            $out[$idx]['weight'] = $newW;
        }

        foreach ($out as $i => $c) {
            unset($out[$i]['raw_weight']);
        }

        return array_values($out);
    }
}

if (!function_exists('tgc_ai_normalize_components')) {
    function tgc_ai_normalize_components(array $json, $targetWeight, array $allowedTypes) {
        $allowedMap = [];
        foreach ($allowedTypes as $t) {
            $t = strtolower(trim((string) $t));
            if ($t !== '') $allowedMap[$t] = true;
        }
        if (count($allowedMap) === 0) {
            $allowedMap = [
                'quiz' => true, 'assignment' => true, 'project' => true,
                'exam' => true, 'participation' => true, 'other' => true,
            ];
        }

        $params = $json['parameters'] ?? ($json['categories'] ?? []);
        if (!is_array($params)) return [];

        $flat = [];
        foreach ($params as $p) {
            if (!is_array($p)) continue;
            $catName = tgc_ai_clean_text((string) ($p['name'] ?? ($p['category'] ?? 'General')), 100);
            if ($catName === '') $catName = 'General';

            $components = $p['components'] ?? ($p['items'] ?? []);
            if (!is_array($components)) continue;
            foreach ($components as $c) {
                if (!is_array($c)) continue;
                $name = tgc_ai_clean_text((string) ($c['name'] ?? ($c['component_name'] ?? '')), 100);
                if ($name === '') continue;
                $code = tgc_ai_clean_text((string) ($c['code'] ?? ($c['component_code'] ?? '')), 50);
                $type = strtolower(trim((string) ($c['type'] ?? ($c['component_type'] ?? 'other'))));
                if (!isset($allowedMap[$type])) $type = 'other';

                $weight = (float) ($c['weight'] ?? ($c['component_weight'] ?? 0));
                if ($weight <= 0) $weight = 1.0;

                $flat[] = [
                    'category_name' => $catName,
                    'component_name' => $name,
                    'component_code' => $code,
                    'component_type' => $type,
                    'raw_weight' => $weight,
                ];
                if (count($flat) >= 80) break 2;
            }
        }

        return tgc_ai_redistribute_weights($flat, $targetWeight);
    }
}

if (!function_exists('tgc_ai_generate_build_draft')) {
    /**
     * Returns [ok(bool), data(array)|message(string)].
     * data keys:
     *  - ready (bool)
     *  - readiness_score (int)
     *  - summary (string)
     *  - knowledge_gaps (array)
     *  - follow_up_questions (array)
     *  - build_name (string)
     *  - build_description (string)
     *  - components (array)
     */
    function tgc_ai_generate_build_draft(array $context, $targetWeight = 100.0, array $allowedTypes = []) {
        if (function_exists('ai_access_can_use') && !ai_access_can_use()) {
            if (function_exists('ai_access_denied_message')) return [false, ai_access_denied_message()];
            return [false, 'AI features are restricted. Student/user accounts are not allowed to use AI.'];
        }

        $answers = is_array($context['answers'] ?? null) ? $context['answers'] : [];
        $local = tgc_ai_local_readiness($answers);
        if (empty($local['ready'])) {
            return [true, [
                'ready' => false,
                'readiness_score' => (int) ($local['readiness_score'] ?? 0),
                'summary' => (string) ($local['summary'] ?? 'Need more context.'),
                'knowledge_gaps' => is_array($local['knowledge_gaps'] ?? null) ? $local['knowledge_gaps'] : [],
                'follow_up_questions' => is_array($local['follow_up_questions'] ?? null) ? $local['follow_up_questions'] : [],
                'build_name' => '',
                'build_description' => '',
                'components' => [],
            ]];
        }

        $apiKey = tgc_ai_openai_api_key();
        if ($apiKey === '') return [false, 'Model 1 API key not configured.'];
        if (!function_exists('curl_init')) return [false, 'cURL extension is not available.'];

        $subjectCode = tgc_ai_clean_text((string) ($context['subject_code'] ?? ''), 50);
        $subjectName = tgc_ai_clean_text((string) ($context['subject_name'] ?? ''), 120);
        $section = tgc_ai_clean_text((string) ($context['section'] ?? ''), 50);
        $academicYear = tgc_ai_clean_text((string) ($context['academic_year'] ?? ''), 30);
        $semester = tgc_ai_clean_text((string) ($context['semester'] ?? ''), 30);
        $yearLevel = tgc_ai_clean_text((string) ($context['year_level'] ?? ''), 30);
        $term = tgc_ai_clean_text((string) ($context['term'] ?? ''), 20);
        $targetWeight = (float) $targetWeight;
        if ($targetWeight <= 0) $targetWeight = 100.0;

        $mustHave = tgc_ai_list_from_lines((string) ($answers['must_have_components'] ?? ''), 10, 120);
        $avoid = tgc_ai_list_from_lines((string) ($answers['avoid_components'] ?? ''), 10, 120);
        $attendanceReq = strtolower(trim((string) ($answers['attendance_requirement'] ?? '')));
        if (!in_array($attendanceReq, ['required', 'optional', 'not_needed'], true)) {
            $attendanceReq = 'optional';
        }

        $payloadContext = [
            'class' => [
                'subject_code' => $subjectCode,
                'subject_name' => $subjectName,
                'section' => $section,
                'academic_year' => $academicYear,
                'semester' => $semester,
                'year_level' => $yearLevel,
                'term' => $term,
                'target_total_weight' => $targetWeight,
            ],
            'teacher_inputs' => [
                'learning_objectives' => tgc_ai_clean_text((string) ($answers['learning_objectives'] ?? ''), 3000),
                'evidence_of_learning' => tgc_ai_clean_text((string) ($answers['evidence_of_learning'] ?? ''), 3000),
                'assessment_strategy' => tgc_ai_clean_text((string) ($answers['assessment_strategy'] ?? ''), 3000),
                'fairness_and_integrity' => tgc_ai_clean_text((string) ($answers['fairness_and_integrity'] ?? ''), 3000),
                'class_constraints' => tgc_ai_clean_text((string) ($answers['class_constraints'] ?? ''), 3000),
                'must_have_components' => $mustHave,
                'avoid_components' => $avoid,
                'attendance_requirement' => $attendanceReq,
            ],
            'allowed_component_types' => array_values(array_map('strval', $allowedTypes)),
        ];

        $systemPrompt = "You are a senior instructional design assistant for college class records. First evaluate whether teacher context is sufficient to build a defensible grading structure. If insufficient, do NOT propose a build and return missing knowledge + follow-up questions. If sufficient, produce a practical category/component build where component weights sum to target_total_weight. Prefer explicit attendance-related component names when attendance_requirement is required. Stay strictly within grading-build context for this class only. Never provide database/schema/table/credential/API-key/server/filesystem/internal-system guidance. Return strict JSON only.";
        $userPrompt = "Return strict JSON with this shape:\n{\n  \"ready\": boolean,\n  \"readiness_score\": 0-100,\n  \"summary\": \"short explanation\",\n  \"knowledge_gaps\": [\"...\"],\n  \"follow_up_questions\": [\"...\"],\n  \"build_name\": \"...\",\n  \"build_description\": \"...\",\n  \"parameters\": [\n    {\n      \"name\": \"Category\",\n      \"weight\": number,\n      \"components\": [\n        {\"name\": \"Component\", \"code\": \"Optional\", \"type\": \"quiz|assignment|project|exam|participation|other\", \"weight\": number}\n      ]\n    }\n  ]\n}\nRules:\n- If ready=false, return parameters as empty array.\n- If ready=true, provide 3 to 8 categories and meaningful components.\n- Ensure sum of component weights is approximately target_total_weight.\n- Keep names concise and school-appropriate.\n- If any input is outside grading-build scope, keep ready=false, keep parameters empty, and redirect to grading-topic guidance only.\n\nContext JSON:\n" . json_encode($payloadContext, JSON_UNESCAPED_SLASHES);

        $payload = [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        if (!$ch) return [false, 'Unable to initialize AI request.'];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 55);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            return [false, 'AI request failed: ' . ($err !== '' ? $err : 'network error')];
        }
        if ($http >= 400) {
            if ($http === 401) return [false, 'AI authentication failed.'];
            if ($http === 429) return [false, 'AI rate limit reached.'];
            if ($http >= 500) return [false, 'AI service temporarily unavailable.'];
            return [false, 'AI request failed (HTTP ' . $http . ').'];
        }

        $decoded = json_decode((string) $resp, true);
        $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        if ($content === '') return [false, 'AI returned an empty response.'];
        $json = tgc_ai_extract_json_object($content);
        if (!is_array($json)) return [false, 'AI returned invalid JSON response.'];

        $ready = !empty($json['ready']);
        $score = (int) ($json['readiness_score'] ?? 0);
        if ($score < 0) $score = 0;
        if ($score > 100) $score = 100;
        $summary = tgc_ai_clean_text((string) ($json['summary'] ?? ''), 400);
        if ($summary === '') $summary = $ready ? 'AI generated a draft build.' : 'AI requested more context.';

        $gaps = [];
        if (is_array($json['knowledge_gaps'] ?? null)) {
            foreach ($json['knowledge_gaps'] as $g) {
                $v = tgc_ai_clean_text((string) $g, 220);
                if ($v !== '') $gaps[] = $v;
                if (count($gaps) >= 8) break;
            }
        }
        $questions = [];
        if (is_array($json['follow_up_questions'] ?? null)) {
            foreach ($json['follow_up_questions'] as $q) {
                $v = tgc_ai_clean_text((string) $q, 220);
                if ($v !== '') $questions[] = $v;
                if (count($questions) >= 6) break;
            }
        }

        if (!$ready) {
            return [true, [
                'ready' => false,
                'readiness_score' => $score,
                'summary' => $summary,
                'knowledge_gaps' => $gaps,
                'follow_up_questions' => $questions,
                'build_name' => '',
                'build_description' => '',
                'components' => [],
            ]];
        }

        $components = tgc_ai_normalize_components($json, $targetWeight, $allowedTypes);
        if (count($components) === 0) {
            return [true, [
                'ready' => false,
                'readiness_score' => max($score, 60),
                'summary' => 'AI could not produce a valid component structure. Please add more concrete details and retry.',
                'knowledge_gaps' => ['Generated build did not contain valid weighted components.'],
                'follow_up_questions' => [
                    'What exact graded outputs will students submit in this term?',
                    'How should the target total weight be split across those outputs?'
                ],
                'build_name' => '',
                'build_description' => '',
                'components' => [],
            ]];
        }

        $buildName = tgc_ai_clean_text((string) ($json['build_name'] ?? ''), 120);
        if ($buildName === '') {
            $buildName = trim($subjectCode . ' ' . $term . ' AI Build');
        }
        $buildDescription = tgc_ai_clean_text((string) ($json['build_description'] ?? ''), 2000);
        if ($buildDescription === '') {
            $buildDescription = 'AI-suggested build draft based on provided class context.';
        }

        return [true, [
            'ready' => true,
            'readiness_score' => max($score, 70),
            'summary' => $summary,
            'knowledge_gaps' => $gaps,
            'follow_up_questions' => $questions,
            'build_name' => $buildName,
            'build_description' => $buildDescription,
            'components' => $components,
        ]];
    }
}

if (!function_exists('tgc_ai_chat_collaborate')) {
    /**
     * Returns [ok(bool), data(array)|message(string)].
     * data keys:
     *  - assistant_message (string)
     *  - ready (bool)
     *  - readiness_score (int)
     *  - summary (string)
     *  - knowledge_gaps (array)
     *  - follow_up_questions (array)
     *  - build_name (string)
     *  - build_description (string)
     *  - components (array)
     */
    function tgc_ai_chat_collaborate(array $context, array $history, $teacherMessage, $targetWeight = 100.0, array $allowedTypes = []) {
        if (function_exists('ai_access_can_use') && !ai_access_can_use()) {
            if (function_exists('ai_access_denied_message')) return [false, ai_access_denied_message()];
            return [false, 'AI features are restricted. Student/user accounts are not allowed to use AI.'];
        }

        $teacherMessage = trim((string) $teacherMessage);
        if ($teacherMessage === '') return [false, 'Message is empty.'];

        [$okTopic, $topicMessage] = tgc_ai_validate_teacher_topic_message($context, $history, $teacherMessage);
        if (!$okTopic) return [false, $topicMessage];

        $apiKey = tgc_ai_openai_api_key();
        if ($apiKey === '') return [false, 'Model 1 API key not configured.'];
        if (!function_exists('curl_init')) return [false, 'cURL extension is not available.'];

        $subjectCode = tgc_ai_clean_text((string) ($context['subject_code'] ?? ''), 50);
        $subjectName = tgc_ai_clean_text((string) ($context['subject_name'] ?? ''), 120);
        $section = tgc_ai_clean_text((string) ($context['section'] ?? ''), 50);
        $academicYear = tgc_ai_clean_text((string) ($context['academic_year'] ?? ''), 30);
        $semester = tgc_ai_clean_text((string) ($context['semester'] ?? ''), 30);
        $yearLevel = tgc_ai_clean_text((string) ($context['year_level'] ?? ''), 30);
        $term = tgc_ai_clean_text((string) ($context['term'] ?? ''), 20);
        $targetWeight = (float) $targetWeight;
        if ($targetWeight <= 0) $targetWeight = 100.0;

        $historyLines = [];
        foreach ($history as $row) {
            if (!is_array($row)) continue;
            $role = strtolower(trim((string) ($row['role'] ?? '')));
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') continue;
            if ($role === 'assistant' || $role === 'ai') {
                $historyLines[] = 'AI: ' . tgc_ai_clean_text($content, 900);
            } else {
                $historyLines[] = 'Teacher: ' . tgc_ai_clean_text($content, 900);
            }
            if (count($historyLines) >= 16) break;
        }

        $contextPayload = [
            'class' => [
                'subject_code' => $subjectCode,
                'subject_name' => $subjectName,
                'section' => $section,
                'academic_year' => $academicYear,
                'semester' => $semester,
                'year_level' => $yearLevel,
                'term' => $term,
                'target_total_weight' => $targetWeight,
            ],
            'allowed_component_types' => array_values(array_map('strval', $allowedTypes)),
            'recent_chat' => $historyLines,
            'latest_teacher_message' => tgc_ai_clean_text($teacherMessage, 1500),
        ];

        $systemPrompt = "You are Ryhn, a collaborative academic assistant inside a teacher chat. Your job is to co-design a class record build through conversation. If details are insufficient, ask concise follow-up questions and do NOT create a build yet. Only mark ready=true when enough details exist to produce a defensible grading structure. Keep tone friendly yet straightforward, concise, clear, and practical. Stay strictly on grading-build topics for the provided class context. Never provide database/schema/table/credential/API-key/server/filesystem/internal-system guidance. Return strict JSON only.";
        $userPrompt = "Return strict JSON with this shape:\n{\n  \"assistant_message\": \"chat reply to teacher\",\n  \"ready\": boolean,\n  \"readiness_score\": 0-100,\n  \"summary\": \"short status\",\n  \"knowledge_gaps\": [\"...\"],\n  \"follow_up_questions\": [\"...\"],\n  \"build_name\": \"...\",\n  \"build_description\": \"...\",\n  \"parameters\": [\n    {\n      \"name\": \"Category\",\n      \"weight\": number,\n      \"components\": [\n        {\"name\": \"Component\", \"code\": \"Optional\", \"type\": \"quiz|assignment|project|exam|participation|other\", \"weight\": number}\n      ]\n    }\n  ]\n}\nRules:\n- If recent_chat has no prior AI reply, begin assistant_message with: \"Hi, I'm Ryhn.\"; otherwise do not re-introduce.\n- If ready=false, parameters must be empty.\n- If ready=true, propose categories/components consistent with teacher instructions and preserve requested order when specified.\n- Keep component weights practical and sum to target_total_weight.\n- If teacher gives explicit percentages/components, honor them unless contradictory.\n- If latest_teacher_message is outside grading-build scope, keep ready=false, keep parameters empty, and reply with a brief redirection to grading-topic questions only.\n- assistant_message should acknowledge and guide next step naturally.\n\nContext JSON:\n" . json_encode($contextPayload, JSON_UNESCAPED_SLASHES);

        $payload = [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        if (!$ch) return [false, 'Unable to initialize AI request.'];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 55);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            return [false, 'AI request failed: ' . ($err !== '' ? $err : 'network error')];
        }
        if ($http >= 400) {
            if ($http === 401) return [false, 'AI authentication failed.'];
            if ($http === 429) return [false, 'AI rate limit reached.'];
            if ($http >= 500) return [false, 'AI service temporarily unavailable.'];
            return [false, 'AI request failed (HTTP ' . $http . ').'];
        }

        $decoded = json_decode((string) $resp, true);
        $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        if ($content === '') return [false, 'AI returned an empty response.'];

        $json = tgc_ai_extract_json_object($content);
        if (!is_array($json)) return [false, 'AI returned invalid JSON response.'];

        $assistantMessage = tgc_ai_clean_text((string) ($json['assistant_message'] ?? ''), 2500);
        if ($assistantMessage === '') $assistantMessage = 'I reviewed your input. Please provide one more clarification.';
        $assistantMessage = tgc_ai_with_ryhn_intro($assistantMessage, $history, true);

        $ready = !empty($json['ready']);
        $score = (int) ($json['readiness_score'] ?? 0);
        if ($score < 0) $score = 0;
        if ($score > 100) $score = 100;
        $summary = tgc_ai_clean_text((string) ($json['summary'] ?? ''), 500);
        if ($summary === '') $summary = $ready ? 'Ready to apply the proposed build.' : 'More details are needed.';

        $gaps = [];
        if (is_array($json['knowledge_gaps'] ?? null)) {
            foreach ($json['knowledge_gaps'] as $g) {
                $v = tgc_ai_clean_text((string) $g, 220);
                if ($v !== '') $gaps[] = $v;
                if (count($gaps) >= 8) break;
            }
        }

        $questions = [];
        if (is_array($json['follow_up_questions'] ?? null)) {
            foreach ($json['follow_up_questions'] as $q) {
                $v = tgc_ai_clean_text((string) $q, 220);
                if ($v !== '') $questions[] = $v;
                if (count($questions) >= 6) break;
            }
        }

        if (!$ready) {
            return [true, [
                'assistant_message' => $assistantMessage,
                'ready' => false,
                'readiness_score' => $score,
                'summary' => $summary,
                'knowledge_gaps' => $gaps,
                'follow_up_questions' => $questions,
                'build_name' => '',
                'build_description' => '',
                'components' => [],
            ]];
        }

        $components = tgc_ai_normalize_components($json, $targetWeight, $allowedTypes);
        if (count($components) === 0) {
            return [true, [
                'assistant_message' => tgc_ai_with_ryhn_intro(
                    'I still need more specific component details before generating a reliable build draft.',
                    $history,
                    true
                ),
                'ready' => false,
                'readiness_score' => min(69, max($score, 55)),
                'summary' => 'Insufficient structure to generate a valid weighted build.',
                'knowledge_gaps' => ['Component-level structure is incomplete or conflicting.'],
                'follow_up_questions' => [
                    'Which exact components should be included per category?',
                    'Do you want strict percentages kept exactly as stated?'
                ],
                'build_name' => '',
                'build_description' => '',
                'components' => [],
            ]];
        }

        $buildName = tgc_ai_clean_text((string) ($json['build_name'] ?? ''), 120);
        if ($buildName === '') {
            $buildName = trim($subjectCode . ' ' . $term . ' Chat Build');
        }
        $buildDescription = tgc_ai_clean_text((string) ($json['build_description'] ?? ''), 2000);
        if ($buildDescription === '') {
            $buildDescription = 'AI chat-collaborated build draft.';
        }

        return [true, [
            'assistant_message' => $assistantMessage,
            'ready' => true,
            'readiness_score' => max($score, 70),
            'summary' => $summary,
            'knowledge_gaps' => $gaps,
            'follow_up_questions' => $questions,
            'build_name' => $buildName,
            'build_description' => $buildDescription,
            'components' => $components,
        ]];
    }
}
