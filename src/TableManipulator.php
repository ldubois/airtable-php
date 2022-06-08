<?php

namespace Ldubois\AirtableSDK;

class TableManipulator
{
    private $client;
    private $table;

    public function __construct(Airtable $client, string $table)
    {
        $this->client = $client;
        $this->table = $table;
    }


    public function createRecord(array $fields, bool $typecast = false): array
    {
        return $this->client->createRecord($this->table, $fields, $typecast);
    }
    
    public function createRecords(array $records, bool $typecast = false): array
    {
        return $this->client->createRecords($this->table, $records, $typecast);
    }

    public function setRecord(array $criteria, array $fields): array
    {
        return $this->client->setRecord($this->table, $criteria, $fields);
    }

    public function updateRecord(array $criteria, array $fields): array
    {
        return $this->client->updateRecord($this->table, $criteria, $fields);
    }

    public function updateRecordById(string $id, array $fields): array
    {
        return $this->client->updateRecordById($this->table, $id, $fields);
    }

    public function containsRecord(array $criteria = []): bool
    {
        return $this->client->containsRecord($this->table, $criteria);
    }

    public function flushRecords(): void
    {
        $this->client->flushRecords($this->table);
    }

    public function deleteRecord(array $criteria = []): array
    {
        return $this->client->deleteRecord($this->table, $criteria);
    }

    public function getRecord(string $id): Record
    {
        return $this->client->getRecord($this->table, $id);
    }

    public function findRecord(array $criteria = []): ?Record
    {
        return $this->client->findRecord($this->table, $criteria);
    }

    public function findRecords(array $criteria = [], string $view = ""): array
    {
        return $this->client->findRecords($this->table, $criteria, $view);
    }

    
    public function findRecordsByFormula(string $formula, string $view = ""): array
    {
        return $this->client->findRecordsByFormula($this->table, $formula, $view);
    }
}
