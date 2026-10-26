<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only, non-authorizing task-aware Skill discovery and composition. */
final class SCP_Skill_Task_Composition {
    const CONTRACT = 'skill-task-composition.v1';
    const MAX_TASK_BYTES = 4000;
    const SOURCE_PAGE_LIMIT = 6;
    const SOURCE_TIME_BUDGET_SECONDS = 18;
    const MAX_RESULTS = 12;

    private static function tokens( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '//u', $value ) ) return array();
        $parts = preg_split( '/[^\p{L}\p{N}]+/u', strtolower( $value ), -1, PREG_SPLIT_NO_EMPTY );
        $stop = array( 'the', 'and', 'for', 'from', 'with', 'into', 'this', 'that', 'are', 'use', 'your', 'have', 'after', 'before', 'please', 'need', 'can', 'want', 'task', 'skill', 'skills', 'project', 'then', 'الى', 'على', 'في', 'من', 'مع', 'عن', 'ثم', 'نفذ', 'تابع', 'راجع' );
        return array_values( array_unique( array_filter( $parts, function ( $part ) use ( $stop ) {
            return strlen( $part ) >= 3 && ! in_array( $part, $stop, true );
        } ) ) );
    }

    private static function validate( $input ) {
        if ( ! is_array( $input ) || ! isset( $input['task'] ) || ! is_string( $input['task'] ) ) {
            return new WP_Error( 'skill_task_invalid', 'A task string is required.' );
        }
        $task = trim( $input['task'] );
        if ( strlen( $task ) < 3 || strlen( $task ) > self::MAX_TASK_BYTES || ! preg_match( '//u', $task ) ) {
            return new WP_Error( 'skill_task_invalid', 'Task must be bounded valid UTF-8 text.' );
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
        return array( 'task' => $task, 'topics' => $topics, 'source_ids' => array_values( array_unique( $source_ids ) ), 'source_offset' => $offset, 'max_results' => $limit );
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
        return array( 'rows' => $rows, 'errors' => $errors, 'next_offset' => $next < count( $all ) ? $next : null, 'total_sources' => count( $all ), 'visited_sources' => $visited );
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
        $query = self::tokens( $v['task'] );
        $topics = self::tokens( implode( ' ', $v['topics'] ) );
        $result = array();
        foreach ( $catalog['rows'] as $row ) {
            $match = self::score( $row, $query, $topics );
            if ( 0 === $match['score'] ) continue;
            $row['score'] = $match['score'];
            $row['matched_terms'] = $match['matched'];
            $result[] = $row;
        }
        usort( $result, function ( $a, $b ) {
            if ( $a['score'] !== $b['score'] ) return $b['score'] - $a['score'];
            if ( $a['origin'] !== $b['origin'] ) return $a['origin'] === 'managed' ? -1 : 1;
            return strcmp( ( $a['logical_id'] ?? $a['path'] ), ( $b['logical_id'] ?? $b['path'] ) );
        } );
        return array(
            'contract' => self::CONTRACT, 'mode' => 'read_only', 'selection_method' => 'deterministic_lexical_v1',
            'task_sha256' => hash( 'sha256', $v['task'] ), 'matched_count' => count( $result ),
            'candidates' => array_slice( $result, 0, $v['max_results'] ),
            'source_page' => array( 'visited' => $catalog['external']['visited_sources'], 'total' => $catalog['external']['total_sources'], 'next_offset' => $catalog['external']['next_offset'], 'errors' => $catalog['external']['errors'] ),
            'trusted_for_execution' => false, 'does_not_grant_authority' => true,
        );
    }

    private static function stage( $name ) {
        $word = implode( ' ', self::tokens( $name ) );
        $patterns = array(
            'diagnose' => '/debug|diagnos|triage|investigat|troubleshoot|audit/',
            'design' => '/design|architect|model|spec|plan|research/',
            'verify' => '/test|verif|quality|review|security|performance|lint/',
            'release' => '/deploy|release|ship|publish|workflow|automation|git/',
        );
        foreach ( $patterns as $stage => $regex ) if ( preg_match( $regex, $word ) ) return $stage;
        return 'implement';
    }

    public static function composition_plan( $input = array() ) {
        $matches = self::match_task( $input );
        if ( is_wp_error( $matches ) ) return $matches;
        $selected = array(); $used = array(); $phase = array();
        foreach ( $matches['candidates'] as $candidate ) {
            $key = strtolower( (string) $candidate['name'] );
            if ( isset( $used[$key] ) ) continue;
            $stage = self::stage( $candidate['name'] );
            if ( isset( $phase[$stage] ) ) continue;
            $used[$key] = true; $phase[$stage] = true;
            $selected[] = array( 'stage' => $stage, 'skill' => $candidate );
            if ( count( $selected ) >= 5 ) break;
        }
        $order = array( 'diagnose' => 10, 'design' => 20, 'implement' => 30, 'verify' => 40, 'release' => 50 );
        usort( $selected, function ( $a, $b ) use ( $order ) {
            return $order[$a['stage']] - $order[$b['stage']];
        } );
        $steps = array();
        foreach ( $selected as $item ) {
            $steps[] = array(
                'order' => count( $steps ) + 1, 'stage' => $item['stage'],
                'skill' => $item['skill'], 'depends_on' => count( $steps ) ? array( count( $steps ) ) : array(),
                'execution' => 'not_authorized',
            );
        }
        $fingerprint = array( 'task_sha256' => $matches['task_sha256'], 'steps' => $steps, 'source_page' => $matches['source_page'] );
        return array(
            'contract' => self::CONTRACT, 'mode' => 'read_only_plan', 'execution_ready' => false,
            'plan_sha256' => hash( 'sha256', json_encode( $fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            'steps' => $steps, 'source_page' => $matches['source_page'],
            'warnings' => array( 'No skill instructions are executed or installed by this plan.', 'External content remains untrusted. Execute only through an independently authorized runner after immutable source verification.' ),
        );
    }
}
