<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only, non-authorizing task-aware Skill discovery and composition. */
final class SCP_Skill_Task_Composition {
    const CONTRACT = 'skill-task-composition.v1';
    const MAX_TASK_BYTES = 4000;
    const SOURCE_PAGE_LIMIT = 6;
    const SOURCE_TIME_BUDGET_SECONDS = 18;
    const MAX_RESULTS = 12;
    const MAX_CONTEXT_COMPANIONS = 3;
    const MAX_CONTEXT_READS = 2;

    private static function tokens( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '//u', $value ) ) return array();
        $parts = preg_split( '/[^\p{L}\p{N}]+/u', strtolower( $value ), -1, PREG_SPLIT_NO_EMPTY );
        $stop = array( 'the', 'and', 'for', 'from', 'with', 'into', 'this', 'that', 'are', 'use', 'your', 'have', 'after', 'before', 'please', 'need', 'can', 'want', 'task', 'skill', 'skills', 'project', 'then', 'الى', 'على', 'في', 'من', 'مع', 'عن', 'ثم', 'نفذ', 'تابع' );
        $aliases = array(
            'debugging' => 'debug', 'fixing' => 'debug', 'bugs' => 'debug', 'bug' => 'debug',
            'عالج' => 'debug', 'اصلح' => 'debug', 'أصلح' => 'debug', 'الأخطاء' => 'debug', 'اخطاء' => 'debug',
            'testing' => 'test', 'tests' => 'test', 'اختبر' => 'test', 'اختبارات' => 'test', 'الاختبارات' => 'test',
            'الأمان' => 'security', 'الامان' => 'security', 'أمان' => 'security', 'الأمن' => 'security', 'امن' => 'security',
            'مراجعة' => 'review', 'راجع' => 'review', 'مراجعه' => 'review', 'افحص' => 'review',
            'الجودة' => 'quality', 'جودة' => 'quality', 'الكود' => 'code', 'الشفرة' => 'code',
            'البرمجة' => 'development', 'تطوير' => 'development', 'طور' => 'development',
            'تصميم' => 'design', 'صمم' => 'design', 'اختيار' => 'selection', 'التوافق' => 'compatibility',
        );
        $normalized = array();
        foreach ( $parts as $part ) {
            if ( strlen( $part ) < 3 || in_array( $part, $stop, true ) ) continue;
            $normalized[] = isset( $aliases[$part] ) ? $aliases[$part] : $part;
        }
        return array_values( array_unique( $normalized ) );
    }

    private static function validate( $input ) {
        if ( ! is_array( $input ) || ! isset( $input['task'] ) || ! is_string( $input['task'] ) ) {
            return new WP_Error( 'skill_task_invalid', 'A task string is required.' );
        }
        $task = trim( $input['task'] );
        if ( strlen( $task ) < 3 || strlen( $task ) > self::MAX_TASK_BYTES || ! preg_match( '//u', $task ) ) {
            return new WP_Error( 'skill_task_invalid', 'Task must be bounded valid UTF-8 text.' );
        }
        $context = isset( $input['task_context'] ) ? $input['task_context'] : '';
        if ( ! is_string( $context ) || strlen( $context ) > self::MAX_TASK_BYTES || ! preg_match( '//u', $context ) ) {
            return new WP_Error( 'skill_context_invalid', 'Task context must be bounded valid UTF-8 text.' );
        }
        $topics = array();
        if ( isset( $input['topics'] ) ) {
            if ( ! is_array( $input['topics'] ) || count( $input['topics'] ) > 12 ) return new WP_Error( 'skill_topics_invalid', 'Too many topics.' );
            foreach ( $input['topics'] as $topic ) {
                if ( ! is_string( $topic ) || strlen( $topic ) > 80 || ! preg_match( '//u', $topic ) ) return new WP_Error( 'skill_topics_invalid', 'Topics must be bounded text.' );
                $topics[] = $topic;
            }
        }
        $source_ids = array();
        if ( isset( $input['source_ids'] ) ) {
            if ( ! is_array( $input['source_ids'] ) || count( $input['source_ids'] ) > 40 ) return new WP_Error( 'skill_sources_invalid', 'Source selection is too large.' );
            foreach ( $input['source_ids'] as $id ) {
                if ( ! is_string( $id ) || ! preg_match( '/\A[a-f0-9]{24}\z/D', $id ) ) return new WP_Error( 'skill_sources_invalid', 'Invalid source identifier.' );
                $source_ids[] = $id;
            }
        }
        $offset = isset( $input['source_offset'] ) ? $input['source_offset'] : 0;
        if ( ! is_int( $offset ) || $offset < 0 || $offset > 40 ) return new WP_Error( 'skill_offset_invalid', 'Invalid source offset.' );
        $limit = isset( $input['max_results'] ) ? $input['max_results'] : 6;
        if ( ! is_int( $limit ) || $limit < 1 || $limit > self::MAX_RESULTS ) return new WP_Error( 'skill_result_limit_invalid', 'Invalid result count.' );
        return array( 'task' => $task, 'task_context' => $context, 'topics' => $topics, 'source_ids' => array_values( array_unique( $source_ids ) ), 'source_offset' => $offset, 'max_results' => $limit );
    }

    private static function source_catalog( array $validated ) {
        $all = SCP_External_Skill_Sources::sources();
        if ( $validated['source_ids'] ) {
            $allowed = array_column( $all, 'id' );
            foreach ( $validated['source_ids'] as $id ) {
                if ( ! in_array( $id, $allowed, true ) ) return new WP_Error( 'skill_source_not_registered', 'Source must be administrator-configured.' );
            }
            $all = array_values( array_filter( $all, function ( $source ) use ( $validated ) {
                return in_array( $source['id'], $validated['source_ids'], true );
            } ) );
        }
        $page = array_slice( $all, $validated['source_offset'], self::SOURCE_PAGE_LIMIT );
        $rows = array();
        $errors = array();
        $visited = 0;
        $started = microtime( true );
        foreach ( $page as $source ) {
            if ( $visited > 0 && microtime( true ) - $started >= self::SOURCE_TIME_BUDGET_SECONDS ) break;
            $visited++;
            $list = SCP_External_Skill_Sources::list_skills( array( 'source_id' => $source['id'] ) );
            if ( is_wp_error( $list ) ) {
                $errors[] = array( 'source_id' => $source['id'], 'code' => $list->get_error_code() );
                continue;
            }
            foreach ( $list['skills'] as $skill ) {
                $rows[] = array(
                    'origin' => 'external_untrusted', 'name' => (string) $skill['name'],
                    'description' => '', 'source_id' => $source['id'],
                    'repository' => $source['repository'], 'ref' => $source['ref'],
                    'tree_sha' => (string) $list['tree_sha'], 'path' => $skill['path'],
                    'blob_sha' => $skill['blob_sha'], 'installed' => false, 'executable' => false,
                );
            }
        }
        $next = $validated['source_offset'] + $visited;
        return array( 'rows' => $rows, 'errors' => $errors, 'next_offset' => $next < count( $all ) ? $next : null, 'total_sources' => count( $all ), 'visited_sources' => $visited, 'page_complete' => $visited === count( $page ) && empty( $errors ), 'complete' => 0 === $validated['source_offset'] && $next >= count( $all ) && empty( $errors ) );
    }

    private static function candidates( array $validated ) {
        $rows = array();
        if ( class_exists( 'MAD4B_SCP_Skill_Registry' ) ) {
            foreach ( MAD4B_SCP_Skill_Registry::list_skills( array( 'enabled' => true ) ) as $skill ) {
                $rows[] = array(
                    'origin' => 'managed', 'name' => (string) $skill['name'],
                    'description' => (string) ( $skill['description'] ?? '' ),
                    'logical_id' => $skill['logical_id'], 'sha256' => $skill['sha256'],
                    'level' => $skill['level'], 'target' => $skill['target'],
                    'installed' => true, 'executable' => false,
                );
            }
        }
        $external = self::source_catalog( $validated );
        if ( is_wp_error( $external ) ) return $external;
        return array( 'rows' => array_merge( $rows, $external['rows'] ), 'external' => $external );
    }

    private static function score( array $candidate, array $query, array $topics ) {
        $name = self::tokens( $candidate['name'] );
        $desc = self::tokens( $candidate['description'] );
        $path = self::tokens( $candidate['path'] ?? '' );
        $score = 0;
        $matched = array();
        foreach ( $query as $word ) {
            $points = in_array( $word, $name, true ) ? 6 : ( in_array( $word, $desc, true ) ? 3 : ( in_array( $word, $path, true ) ? 1 : 0 ) );
            if ( $points ) { $score += $points; $matched[] = $word; }
        }
        foreach ( $topics as $topic ) {
            if ( in_array( $topic, $name, true ) || in_array( $topic, $desc, true ) || in_array( $topic, $path, true ) ) {
                $score += 8;
                $matched[] = $topic;
            }
        }
        return array( 'score' => $score, 'matched' => array_values( array_unique( $matched ) ) );
    }

    public static function match_task( $input = array() ) {
        $v = self::validate( $input );
        if ( is_wp_error( $v ) ) return $v;
        $catalog = self::candidates( $v );
        if ( is_wp_error( $catalog ) ) return $catalog;
        $query = self::tokens( $v['task'] . ' ' . $v['task_context'] );
        $topics = self::tokens( implode( ' ', $v['topics'] ) );
        $result = array();
        foreach ( $catalog['rows'] as $row ) {
            $match = self::score( $row, $query, $topics );
            if ( 0 === $match['score'] ) continue;
            $row['score'] = $match['score'];
            $row['matched_terms'] = $match['matched'];
            $result[] = $row;
        }
        // Build a knowledge family, not a deletion rule. Local instructions
        // keep precedence for future authorized execution; external Skills
        // remain separately attributed, untrusted advisory context.
        $managed_index = array();
        foreach ( $result as $index => $candidate ) {
            if ( 'managed' === $candidate['origin'] ) {
                $key = strtolower( trim( $candidate['name'] ) );
                if ( ! isset( $managed_index[$key] ) ) $managed_index[$key] = $index;
            }
        }
        $external_contexts_attached = 0;
        $context_companions_omitted = 0;
        $families = array();
        foreach ( $result as $index => $candidate ) {
            if ( 'managed' === $candidate['origin'] ) {
                $candidate['context_companions'] = array();
                $candidate['context_companions_omitted'] = 0;
                $families[$index] = $candidate;
            }
        }
        foreach ( $result as $index => $candidate ) {
            if ( 'managed' === $candidate['origin'] ) continue;
            $key = strtolower( trim( $candidate['name'] ) );
            if ( ! isset( $managed_index[$key] ) ) {
                $families[$index] = $candidate;
                continue;
            }
            $owner = $managed_index[$key];
            $companion = array(
                'origin' => 'external_untrusted',
                'name' => $candidate['name'],
                'source_id' => $candidate['source_id'],
                'repository' => $candidate['repository'],
                'ref' => $candidate['ref'],
                'tree_sha' => $candidate['tree_sha'],
                'path' => $candidate['path'],
                'blob_sha' => $candidate['blob_sha'],
                'matched_terms' => $candidate['matched_terms'],
                'trust' => 'untrusted_external_instructions',
                'context_use' => 'advisory_only',
                'executable' => false,
            );
            if ( count( $families[$owner]['context_companions'] ) >= self::MAX_CONTEXT_COMPANIONS ) {
                $families[$owner]['context_companions_omitted']++;
                $context_companions_omitted++;
                continue;
            }
            $families[$owner]['context_companions'][] = $companion;
            $external_contexts_attached++;
        }
        $result = array_values( $families );
        usort( $result, function ( $a, $b ) {
            if ( $a['score'] !== $b['score'] ) return $b['score'] - $a['score'];
            if ( $a['origin'] !== $b['origin'] ) return $a['origin'] === 'managed' ? -1 : 1;
            return strcmp( ( $a['logical_id'] ?? $a['path'] ), ( $b['logical_id'] ?? $b['path'] ) );
        } );
        return array(
            'contract' => self::CONTRACT, 'mode' => 'read_only', 'selection_method' => 'deterministic_lexical_v1',
            'task_sha256' => hash( 'sha256', $v['task'] . chr( 0 ) . $v['task_context'] ), 'matched_count' => count( $result ),
            // Retained for existing callers; no colliding source is silently
            // dropped from knowledge discovery. Bounded omitted count is separate.
            'external_name_collisions_suppressed' => 0,
            'external_contexts_attached' => $external_contexts_attached,
            'context_companions_omitted' => $context_companions_omitted,
            'candidates' => array_slice( $result, 0, $v['max_results'] ),
            'source_page' => array( 'visited' => $catalog['external']['visited_sources'], 'total' => $catalog['external']['total_sources'], 'next_offset' => $catalog['external']['next_offset'], 'errors' => $catalog['external']['errors'], 'page_complete' => $catalog['external']['page_complete'], 'complete' => $catalog['external']['complete'] ),
            'trusted_for_execution' => false, 'does_not_grant_authority' => true,
        );
    }

    private static function stage( $name ) {
        $word = implode( ' ', self::tokens( $name ) );
        $patterns = array(
            'diagnose' => '/debug|diagnos|triage|investigat|troubleshoot|audit/',
            'design' => '/design|architect|model|spec|plan|research/',
            'implement' => '/develop|implement|test driven/',
            'verify' => '/test|verif|quality|review|security|performance|lint/',
            'release' => '/deploy|release|ship|publish|workflow|automation|git/',
        );
        foreach ( $patterns as $stage => $regex ) if ( preg_match( $regex, $word ) ) return $stage;
        return 'implement';
    }

    private static function bounded_context_text( $text, $max_bytes = 320 ) {
        if ( ! is_string( $text ) || ! preg_match( '//u', $text ) ) return '';
        $text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) );
        if ( strlen( $text ) <= $max_bytes ) return $text;
        $text = substr( $text, 0, $max_bytes );
        while ( '' !== $text && ! preg_match( '//u', $text ) ) $text = substr( $text, 0, -1 );
        return $text;
    }

    private static function advisory_context( array $companion, $read_allowed ) {
        $entry = $companion;
        $entry['state'] = 'not_loaded_budget';
        $entry['description'] = '';
        $entry['compatibility'] = '';
        $entry['headings'] = array();
        if ( ! $read_allowed ) return $entry;
        $entry['state'] = 'unavailable';
        if ( ! method_exists( 'SCP_External_Skill_Sources', 'get_skill' ) ) {
            $entry['error_code'] = 'skill_source_reader_unavailable';
            return $entry;
        }
        $doc = SCP_External_Skill_Sources::get_skill( array(
            'source_id' => $companion['source_id'],
            'path' => $companion['path'],
            'blob_sha' => $companion['blob_sha'],
        ) );
        if ( is_wp_error( $doc ) ) {
            $entry['error_code'] = $doc->get_error_code();
            return $entry;
        }
        if ( ! is_array( $doc ) || ! isset( $doc['source']['id'], $doc['path'], $doc['blob_sha'],
            $doc['content'], $doc['sha256'], $doc['bytes'], $doc['trust'] )
            || ! is_string( $doc['content'] )
            || strlen( $doc['content'] ) > 262144 || ! preg_match( '//u', $doc['content'] )
            || ! is_string( $doc['source']['id'] ) || ! is_string( $doc['path'] )
            || ! is_string( $doc['blob_sha'] ) || ! is_string( $doc['sha256'] )
            || ! is_int( $doc['bytes'] ) || ! is_string( $doc['trust'] )
            || ! hash_equals( $companion['source_id'], $doc['source']['id'] )
            || ! hash_equals( $companion['blob_sha'], $doc['blob_sha'] )
            || ! hash_equals( $companion['path'], $doc['path'] )
            || ! hash_equals( hash( 'sha256', $doc['content'] ), $doc['sha256'] )
            || strlen( $doc['content'] ) !== $doc['bytes']
            || 'untrusted_external_instructions' !== $doc['trust']
            || ! empty( $doc['installed'] ) || ! empty( $doc['executed'] ) ) {
            $entry['error_code'] = 'skill_companion_provenance_unverified';
            return $entry;
        }
        $entry['state'] = 'verified_untrusted_metadata';
        $entry['sha256'] = $doc['sha256'];
        // Parse limited descriptive fields, not instructions, scripts or an
        // untrusted YAML object that could override the control plane.
        $prefix = substr( $doc['content'], 0, 5000 );
        if ( preg_match( '/\A---\r?\n(.*?)\r?\n---(?:\r?\n|\z)/s', $prefix, $frontmatter ) ) {
            foreach ( array( 'description', 'compatibility' ) as $field ) {
                if ( preg_match( '/^' . $field . ':\s*(.+)$/mi', $frontmatter[1], $part ) ) {
                    $raw = trim( $part[1] );
                    if ( strlen( $raw ) >= 2 && ( $raw[0] === '"' || $raw[0] === "'" )
                        && substr( $raw, -1 ) === $raw[0] ) $raw = substr( $raw, 1, -1 );
                    if ( '|' !== $raw && '>' !== $raw ) {
                        $entry[$field] = self::bounded_context_text( $raw );
                    }
                }
            }
        }
        if ( preg_match_all( '/^#{1,3}\s+(.{1,180})$/mu', $prefix, $titles ) ) {
            foreach ( array_slice( $titles[1], 0, 5 ) as $heading ) {
                $safe = self::bounded_context_text( $heading, 180 );
                if ( '' !== $safe ) $entry['headings'][] = $safe;
            }
        }
        return $entry;
    }

    public static function composition_plan( $input = array() ) {
        $matches = self::match_task( $input );
        if ( is_wp_error( $matches ) ) return $matches;
        $selected = array(); $used = array(); $stage_terms = array(); $stage_counts = array();
        foreach ( $matches['candidates'] as $candidate ) {
            $key = strtolower( (string) $candidate['name'] );
            if ( isset( $used[$key] ) ) continue;
            $stage = self::stage( $candidate['name'] );
            $covered = isset( $stage_terms[$stage] ) ? $stage_terms[$stage] : array();
            $novel = array_diff( $candidate['matched_terms'], $covered );
            if ( isset( $stage_counts[$stage] ) && ( ! $novel || $stage_counts[$stage] >= 2 ) ) continue;
            $used[$key] = true;
            $stage_counts[$stage] = isset( $stage_counts[$stage] ) ? $stage_counts[$stage] + 1 : 1;
            $stage_terms[$stage] = array_values( array_unique( array_merge( $covered, $candidate['matched_terms'] ) ) );
            $selected[] = array( 'stage' => $stage, 'skill' => $candidate );
            if ( count( $selected ) >= 5 ) break;
        }
        $order = array( 'diagnose' => 10, 'design' => 20, 'implement' => 30, 'verify' => 40, 'release' => 50 );
        usort( $selected, function ( $a, $b ) use ( $order ) {
            return $order[$a['stage']] - $order[$b['stage']];
        } );
        $steps = array();
        $remaining_context_reads = self::MAX_CONTEXT_READS;
        $context_warnings = array();
        foreach ( $selected as $item ) {
            $steps[] = array(
                'order' => count( $steps ) + 1, 'stage' => $item['stage'],
                'skill' => $item['skill'], 'depends_on' => count( $steps ) ? array( count( $steps ) ) : array(),
                'execution' => 'not_authorized',
            );
            if ( 'managed' === $item['skill']['origin']
                && ! empty( $item['skill']['context_companions'] ) ) {
                $advisories = array();
                $local_terms = self::tokens( $item['skill']['name'] . ' ' . $item['skill']['description'] );
                $external_terms = array();
                foreach ( $item['skill']['context_companions'] as $companion ) {
                    $load = $remaining_context_reads > 0;
                    if ( $load ) $remaining_context_reads--;
                    $advisory = self::advisory_context( $companion, $load );
                    if ( 'verified_untrusted_metadata' !== $advisory['state'] ) {
                        $context_warnings['incomplete'] = 'Some external context was unavailable or not inspected within the bounded read budget.';
                    }
                    if ( 'verified_untrusted_metadata' === $advisory['state'] ) {
                        $external_terms = array_merge( $external_terms, self::tokens(
                            $advisory['description'] . ' ' . implode( ' ', $advisory['headings'] )
                        ) );
                    }
                    $advisories[] = $advisory;
                }
                if ( ! empty( $item['skill']['context_companions_omitted'] ) ) {
                    $context_warnings['omitted'] = 'Additional external companions exist beyond the per-Skill context limit.';
                }
                $steps[count( $steps ) - 1]['composition_context'] = array(
                    'managed_context' => array(
                        'logical_id' => $item['skill']['logical_id'],
                        'sha256' => $item['skill']['sha256'],
                        'description' => $item['skill']['description'],
                    ),
                    'external_contexts' => $advisories,
                    'shared_topics' => array_slice( array_values( array_intersect( array_unique( $external_terms ), $local_terms ) ), 0, 12 ),
                    'additional_external_topics' => array_slice( array_values( array_diff( array_unique( $external_terms ), $local_terms ) ), 0, 12 ),
                    'external_instructions_applied' => false,
                    'execution_precedence' => 'managed',
                    'conflict_status' => 'potential_conflicts_not_semantically_verified',
                    'resolution' => 'retain_external_advice_apply_managed_execution_constraints',
                    'review_required_before_mutation' => true,
                );
            }
        }
        $fingerprint = array( 'task_sha256' => $matches['task_sha256'], 'steps' => $steps, 'source_page' => $matches['source_page'] );
        return array(
            'contract' => self::CONTRACT, 'mode' => 'read_only_plan', 'execution_ready' => false,
            'plan_sha256' => hash( 'sha256', json_encode( $fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            'steps' => $steps, 'source_page' => $matches['source_page'],
            'warnings' => array_merge( array( 'No skill instructions are executed or installed by this plan.', 'External content remains untrusted. Execute only through an independently authorized runner after immutable source verification.' ), $matches['source_page']['complete'] ? array() : array( 'The registered external sources were only partially inspected or some source reads failed; selected matches are not exhaustive.' ), array_values( $context_warnings ) ),
        );
    }
}
