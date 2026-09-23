<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class SanctionsImportService
{
    public const SOURCES = [1 => 'ONU', 2 => 'EU', 3 => 'OFAC'];
    public const URLS = [
        1 => 'https://scsanctions.un.org/resources/xml/en/consolidated.xml',
        2 => 'https://webgate.ec.europa.eu/fsd/fsf/public/files/xmlFullSanctionsList/content',
        3 => 'https://www.treasury.gov/ofac/downloads/sdnlist.txt',
    ];

    public function apply(int $listId, array $entries, string $filename): void
    {
        $source = self::SOURCES[$listId];
        // Verrou partagé par tous les imports de cette liste, même sans index unique historique sur les entrées.
        DB::table('screening_lists')->where('id', $listId)->lockForUpdate()->first();
        foreach (array_chunk($entries, 200) as $chunk) {
            $refs = array_column($chunk, 'reference');
            $known = DB::table('screening_list_entries')->where('screening_list_id', $listId)
                ->whereIn('external_reference', $refs)->pluck('id', 'external_reference');
            $new = []; $updates = []; $entities = [];
            foreach ($chunk as $entry) {
                $row = [
                    'screening_list_id' => $listId, 'external_reference' => $entry['reference'],
                    'entity_type' => $entry['type'], 'primary_name' => $entry['name'], 'normalized_name' => $entry['normalized'],
                    'aliases' => json_encode($entry['aliases'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'program' => $entry['program'], 'source_url' => self::URLS[$listId], 'source_updated_at' => now(),
                    'is_active' => true, 'updated_at' => now(),
                ];
                if (isset($known[$entry['reference']])) $updates[] = ['id' => $known[$entry['reference']], ...$row];
                else $new[] = $row;
                $entities[] = [
                    'screening_list_id' => $listId, 'source' => $source, 'source_list' => $source,
                    'source_entity_id' => $entry['reference'], 'source_reference' => $entry['reference'],
                    'primary_name' => $entry['name'], 'normalized_name' => $entry['normalized'], 'entity_type' => $entry['type'],
                    'programme' => $entry['program'], 'source_original_file' => $filename,
                    'source_last_updated_at' => now(), 'is_active' => true, 'updated_at' => now(),
                ];
            }
            if ($new) DB::table('screening_list_entries')->insert($new);
            if ($updates) DB::table('screening_list_entries')->upsert($updates, ['id'], ['entity_type', 'primary_name', 'normalized_name', 'aliases', 'program', 'source_url', 'source_updated_at', 'is_active', 'updated_at']);
            DB::table('sanctions_entities')->upsert($entities, ['source', 'source_entity_id'], ['screening_list_id', 'primary_name', 'normalized_name', 'entity_type', 'programme', 'source_original_file', 'source_last_updated_at', 'is_active', 'updated_at']);
            $entityIds = DB::table('sanctions_entities')->where('source', $source)->whereIn('source_entity_id', $refs)->pluck('id', 'source_entity_id');
            $existingAliases = DB::table('sanctions_aliases')->whereIn('sanctions_entity_id', $entityIds->values())->get(['sanctions_entity_id', 'alias_name']);
            $keys = [];
            foreach ($existingAliases as $alias) $keys[$alias->sanctions_entity_id.'|'.$alias->alias_name] = true;
            $aliases = [];
            foreach ($chunk as $entry) foreach ($entry['aliases'] as $alias) {
                $id = $entityIds[$entry['reference']];
                $key = $id.'|'.$alias;
                if (! isset($keys[$key])) {
                    $aliases[] = ['sanctions_entity_id' => $id, 'source' => $source, 'alias_name' => mb_substr($alias, 0, 500), 'alias_type' => 'ALIAS'];
                    $keys[$key] = true;
                }
            }
            foreach (array_chunk($aliases, 200) as $aliasChunk) DB::table('sanctions_aliases')->insert($aliasChunk);
        }
        DB::table('screening_lists')->where('id', $listId)->update(['last_update' => now()->toDateString()]);
    }
}
