<?php

declare(strict_types=1);

/**
 * Модель для работы с записями, ожидающими фоновой проверки.
 *
 * ВАЖНО: в хуке entry_before_add запись ещё не имеет ID в БД, поэтому
 * метка "Непроверено" устанавливается через _tags() и попадает только
 * в поле `tags` таблицы `_entry`. Таблица `_entrytag` при этом НЕ
 * заполняется. Поэтому поиск и удаление метки делаем по полю `tags`.
 */
class FreshExtension_AutoFilter_PendingEntries_Model extends Minz_ModelPdo
{
    /**
     * Возвращает ID записей с меткой "Непроверено".
     *
     * Ищем по полю `tags` в `_entry`, т.к. в хуке entry_before_add
     * запись ещё не сохранена и `_entrytag` не заполняется.
     *
     * @param int $pendingTagId ID метки "Непроверено"
     * @param int $limit Максимальное количество
     * @param array<int, string> $channelsFilter Список ID каналов (пустой = все)
     * @return list<string>
     */
    public function getPendingEntryIds(int $pendingTagId, int $limit, array $channelsFilter = []): array
    {
        $tagPattern = 't:' . $pendingTagId;

        $sql = <<<'SQL'
            SELECT id
            FROM `_entry`
            WHERE (
                tags = ? OR
                tags LIKE ? ESCAPE '\' OR
                tags LIKE ? ESCAPE '\' OR
                tags LIKE ? ESCAPE '\'
            )
        SQL;

        $params = [
            $tagPattern,
            $tagPattern . ';%',
            '%;' . $tagPattern . ';%',
            '%;' . $tagPattern,
        ];

        if (!empty($channelsFilter)) {
            $placeholders = implode(',', array_fill(0, count($channelsFilter), '?'));
            $sql .= " AND id_feed IN ({$placeholders})";
            foreach ($channelsFilter as $feedId) {
                $params[] = (int)$feedId;
            }
        }

        $sql .= ' ORDER BY date DESC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        $stm = $this->pdo->prepare($sql);
        if ($stm === false) {
            return [];
        }

        if (!$stm->execute($params)) {
            return [];
        }

        $entryIds = [];
        while (is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
            if (!empty($row['id'])) {
                $entryIds[] = (string)$row['id'];
            }
        }

        return $entryIds;
    }

    /**
     * Удаляет метку у существующей записи.
     *
     * Удаляет из `_entrytag` (на случай, если запись там есть) и
     * обновляет поле `tags` в `_entry`, убирая `t:{tagId}`.
     */
    public function removeTagFromEntry(int $tagId, string $entryId): bool
    {
        // Пытаемся удалить из _entrytag (могло попасть туда при ручном тегировании)
        $sql = 'DELETE FROM `_entrytag` WHERE id_tag = :id_tag AND id_entry = :id_entry';
        $stm = $this->pdo->prepare($sql);
        if ($stm !== false) {
            $stm->bindValue(':id_tag', $tagId, PDO::PARAM_INT);
            $stm->bindValue(':id_entry', $entryId, PDO::PARAM_STR);
            $stm->execute();
        }

        // Обновляем поле tags в _entry
        $currentTags = $this->getEntryTags($entryId);
        if ($currentTags === null || $currentTags === '' || $currentTags === null) {
            return true;
        }

        $tagPattern = 't:' . $tagId;
        $parts = explode(';', $currentTags);
        $newParts = [];
        foreach ($parts as $part) {
            if ($part !== '' && $part !== $tagPattern) {
                $newParts[] = $part;
            }
        }
        $newTags = implode(';', $newParts);

        if ($newTags === $currentTags) {
            return true;
        }

        $updateSql = 'UPDATE `_entry` SET tags = :tags WHERE id = :id';
        $updateStm = $this->pdo->prepare($updateSql);
        if ($updateStm === false) {
            return false;
        }

        if ($updateStm->bindValue(':tags', $newTags, PDO::PARAM_STR)
            && $updateStm->bindValue(':id', $entryId, PDO::PARAM_STR)
            && $updateStm->execute()
        ) {
            return true;
        }

        $info = $updateStm->errorInfo();
        Minz_Log::warning('AutoFilter: Failed to update entry tags: ' . json_encode($info));
        return false;
    }

    /**
     * Добавляет метку (t:{tagId}) в поле `tags` записи `_entry`.
     * Нужно для синхронизации с `_entrytag`.
     */
    public function addTagToEntry(int $tagId, string $entryId): bool
    {
        $currentTags = $this->getEntryTags($entryId);
        if ($currentTags === null) {
            return false;
        }

        $tagPattern = 't:' . $tagId;
        if ($currentTags === '') {
            $newTags = $tagPattern;
        } else {
            $parts = explode(';', $currentTags);
            if (in_array($tagPattern, $parts, true)) {
                return true;
            }
            $parts[] = $tagPattern;
            $newTags = implode(';', $parts);
        }

        $updateSql = 'UPDATE `_entry` SET tags = :tags WHERE id = :id';
        $updateStm = $this->pdo->prepare($updateSql);
        if ($updateStm === false) {
            return false;
        }

        if ($updateStm->bindValue(':tags', $newTags, PDO::PARAM_STR)
            && $updateStm->bindValue(':id', $entryId, PDO::PARAM_STR)
            && $updateStm->execute()
        ) {
            return true;
        }

        $info = $updateStm->errorInfo();
        Minz_Log::warning('AutoFilter: Failed to add tag to entry tags field: ' . json_encode($info));
        return false;
    }

    private function getEntryTags(string $entryId): ?string
    {
        $sql = 'SELECT tags FROM `_entry` WHERE id = :id';
        $stm = $this->pdo->prepare($sql);
        if ($stm === false) {
            return null;
        }
        $stm->bindValue(':id', $entryId, PDO::PARAM_STR);
        if (!$stm->execute()) {
            return null;
        }
        $row = $stm->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return $row['tags'] ?? null;
    }
}
