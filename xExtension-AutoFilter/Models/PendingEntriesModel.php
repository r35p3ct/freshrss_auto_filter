<?php

declare(strict_types=1);

/**
 * Модель для работы с записями, ожидающими фоновой проверки.
 */
class FreshExtension_AutoFilter_PendingEntries_Model extends Minz_ModelPdo
{
    /**
     * Возвращает ID записей с меткой "Непроверено".
     *
     * @param int $pendingTagId ID метки "Непроверено"
     * @param int $limit Максимальное количество
     * @param array<int, string> $channelsFilter Список ID каналов (пустой = все)
     * @return list<string>
     */
    public function getPendingEntryIds(int $pendingTagId, int $limit, array $channelsFilter = []): array
    {
        $sql = <<<'SQL'
            SELECT et.id_entry
            FROM `_entrytag` et
            INNER JOIN `_entry` e ON et.id_entry = e.id
            WHERE et.id_tag = :id_tag
        SQL;

        $params = [':id_tag' => $pendingTagId];

        if (!empty($channelsFilter)) {
            $placeholders = implode(',', array_fill(0, count($channelsFilter), '?'));
            $sql .= " AND e.id_feed IN ({$placeholders})";
            foreach ($channelsFilter as $feedId) {
                $params[] = (int)$feedId;
            }
        }

        $sql .= ' ORDER BY e.date DESC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        $stm = $this->pdo->prepare($sql);
        if ($stm === false) {
            return [];
        }

        if (!$stm->execute(array_values($params))) {
            return [];
        }

        $entryIds = [];
        while (is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
            if (!empty($row['id_entry'])) {
                $entryIds[] = (string)$row['id_entry'];
            }
        }

        return $entryIds;
    }

    /**
     * Удаляет метку у существующей записи.
     */
    public function removeTagFromEntry(int $tagId, string $entryId): bool
    {
        $sql = 'DELETE FROM `_entrytag` WHERE id_tag = :id_tag AND id_entry = :id_entry';
        $stm = $this->pdo->prepare($sql);

        if ($stm === false) {
            return false;
        }

        if ($stm->bindValue(':id_tag', $tagId, PDO::PARAM_INT)
            && $stm->bindValue(':id_entry', $entryId, PDO::PARAM_STR)
            && $stm->execute()
        ) {
            return true;
        }

        $info = $stm->errorInfo();
        Minz_Log::warning('AutoFilter: Failed to remove tag: ' . json_encode($info));
        return false;
    }
}
