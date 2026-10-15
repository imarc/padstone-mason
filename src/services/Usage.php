<?php

namespace imarc\padstonemason\services;

use craft\base\Component;
use craft\db\Query;
use DateTimeImmutable;
use imarc\padstonemason\records\UsageRecord;
use yii\db\Expression;

class Usage extends Component
{
    public function record(?int $userId, string $provider, string $model, ?int $promptTokens, ?int $completionTokens): void
    {
        if (!$userId || ($promptTokens === null && $completionTokens === null)) {
            return;
        }

        $record = new UsageRecord();
        $record->userId = $userId;
        $record->provider = $provider;
        $record->model = $model;
        $record->promptTokens = (int)$promptTokens;
        $record->completionTokens = (int)$completionTokens;
        $record->save(false);
    }

    /**
     * @return array<int, array{userId: int, username: string, provider: string, model: string, promptTokens: int, completionTokens: int}>
     */
    public function totals(?string $from, ?string $to): array
    {
        $query = (new Query())
            ->select([
                'userId' => 'usage.userId',
                'username' => 'users.username',
                'provider' => 'usage.provider',
                'model' => 'usage.model',
                'promptTokens' => new Expression('SUM([[usage.promptTokens]])'),
                'completionTokens' => new Expression('SUM([[usage.completionTokens]])'),
            ])
            ->from(['usage' => UsageRecord::tableName()])
            ->leftJoin('{{%users}} users', '[[users.id]] = [[usage.userId]]')
            ->groupBy(['usage.userId', 'users.username', 'usage.provider', 'usage.model'])
            ->orderBy(['users.username' => SORT_ASC, 'usage.provider' => SORT_ASC]);

        if ($from) {
            $query->andWhere(['>=', 'usage.dateCreated', (new DateTimeImmutable($from))->format('Y-m-d 00:00:00')]);
        }
        if ($to) {
            $query->andWhere(['<=', 'usage.dateCreated', (new DateTimeImmutable($to))->format('Y-m-d 23:59:59')]);
        }

        return array_map(fn(array $row) => [
            'userId' => (int)$row['userId'],
            'username' => (string)$row['username'],
            'provider' => (string)$row['provider'],
            'model' => (string)$row['model'],
            'promptTokens' => (int)$row['promptTokens'],
            'completionTokens' => (int)$row['completionTokens'],
        ], $query->all());
    }
}
