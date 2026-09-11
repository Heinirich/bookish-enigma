<?php

namespace App\Agent\Schemas;

/**
 * The shape every conclusion must take.
 *
 * There is no free-prose path out of the synthesis step: the model cannot state
 * a cause except as an object carrying its supporting evidence and a confidence
 * value. llama.cpp compiles this into a grammar, so the structure is guaranteed
 * at generation time -- what remains for the validator is whether the *contents*
 * refer to anything real.
 */
class HypothesisSchema
{
    public static function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'incident_hypotheses',
                'strict' => true,
                'schema' => self::schema(),
            ],
        ];
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['hypotheses', 'summary'],
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'One or two sentences describing what the data shows. State only what the evidence supports.',
                ],
                'hypotheses' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 3,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'statement',
                            'mechanism',
                            'remediation',
                            'stance',
                            'root_cause_sha',
                            'probability_this_explains_the_incident',
                            'evidence_ids',
                            'contradicting_evidence_ids',
                        ],
                        'properties' => [
                            'statement' => [
                                'type' => 'string',
                                'description' => 'A single claim. Cite concrete figures from the evidence rather than describing them loosely. '
                                    .'For stance "cause", state what caused the incident. For stance "ruled_out", state what was '
                                    .'considered and why it does not explain the symptom — do not phrase it as a cause.',
                            ],
                            'mechanism' => [
                                'type' => 'string',
                                'description' => 'What in the changed code produces this symptom, in one or two sentences. '
                                    .'Refer to the actual lines: a query added inside a loop, a timeout lowered below the '
                                    .'upstream response time, a cache lifetime shortened, a renamed binding an old call site '
                                    .'still resolves. For a ruled-out candidate, say what the change does instead and why that '
                                    .'cannot produce the symptom. Never restate the conclusion here.',
                            ],
                            'remediation' => [
                                'type' => 'string',
                                'description' => 'The next action a responder should take, in one sentence. Prefer the '
                                    .'reversible option first — reverting a deploy beats designing a fix during an '
                                    .'incident. Name only things visible in the evidence: a specific commit to revert, '
                                    .'a setting to restore to its previous value. Do not invent commands, file paths or '
                                    .'infrastructure you have not seen. For a ruled-out candidate write "No action needed".',
                            ],
                            'stance' => [
                                'type' => 'string',
                                'enum' => ['cause', 'ruled_out'],
                                'description' => 'Use "cause" when you believe this explains the incident. Use "ruled_out" when you '
                                    .'examined a candidate and are excluding it. Never write a ruled-out candidate as though it were a cause.',
                            ],
                            'root_cause_sha' => [
                                'type' => ['string', 'null'],
                                'description' => 'Short SHA of the deployment being blamed, exactly as it appears in the evidence. Null if no deployment is implicated.',
                            ],
                            /*
                            | Named for exactly what it measures.
                            |
                            | As "confidence" the model read it as certainty in its
                            | own stance and put 0.99 on a ruled-out candidate,
                            | which inverts the number and corrupts calibration.
                            | The description said otherwise; the field name won.
                            */
                            'probability_this_explains_the_incident' => [
                                'type' => 'number',
                                'minimum' => 0,
                                'maximum' => 1,
                                // One meaning, always: the probability this explains the
                                // incident. A ruled-out candidate therefore sits near 0,
                                // which is consistent rather than a second scale to learn.
                                'description' => 'How likely it is that THIS is what caused the incident. Near 1 means it '
                                    .'almost certainly caused it; near 0 means it almost certainly did not. Anything with '
                                    .'stance "ruled_out" must be near 0. This is not how sure you are of your own reasoning.',
                            ],
                            'evidence_ids' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => ['type' => 'string'],
                                'description' => 'Evidence IDs supporting this claim. Use only IDs shown in the EVIDENCE section, exactly as written (for example "EV-3").',
                            ],
                            'contradicting_evidence_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Evidence IDs that weigh against this claim. Empty array if none.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
