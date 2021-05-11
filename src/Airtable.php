<?php

namespace Ldubois\AirtableSDK;

use Assert\Assertion;
use Buzz;
use Buzz\Client\AbstractClient;
use Buzz\Message\Response;
use phpDocumentor\Reflection\Types\Boolean;

class Airtable
{
    /** @var Buzz\Browser */
    protected $browser;

    /** @var string */
    private $base;

    public function __construct(string $accessToken, string $base, int $timeout = 10)
    {
        // @see https://github.com/kriswallsmith/Buzz/pull/186
        $listener = new Buzz\Listener\CallbackListener(function (Buzz\Message\RequestInterface $request, $response = null) use ($accessToken) {
            if ($response) {
                // postSend
            } else {
                // preSend
                $request->addHeader(sprintf('Authorization: Bearer %s', $accessToken));
            }
        });

        $this->browser = new Buzz\Browser(new Buzz\Client\Curl());
        $this->browser->addListener($listener);

        
        /** @var AbstractClient */
        $client = $this->browser->getClient();
        $client->setTimeout($timeout);

        $this->base = $base;
    }

    public function createTableManipulator(string $table): TableManipulator
    {
        return new TableManipulator($this, $table);
    }

    public function createRecord(string $table, array $fields): array
    {
        /** @var Response $response */
        $response = $this->browser->post(
            $this->getEndpoint($table),
            [
                'content-type' => 'application/json',
            ],
            json_encode([
                'fields' => $fields,
            ])
        );

        return $this->guardResponse($table, $response);
    }

    /**
     * This will update all fields of a table record, issuing a PUT request to the record endpoint. Any fields that are not included will be cleared ().
     *
     * @throws \Assert\AssertionFailedException
     */
    public function setRecord(string $table, array $criteria, array $fields): array
    {
        $record = $this->findRecord($table, $criteria);

        Assertion::notNull($record, 'Record not found');

        /** @var Response $response */
        $response = $this->browser->put(
            $this->getEndpoint($table, $record->getId()),
            [
                'content-type' => 'application/json',
            ],
            json_encode([
                'fields' => $fields,
            ])
        );

        return $this->guardResponse($table, $response);
    }

    /**
     * This will update some (but not all) fields of a table record, issuing a PATCH request to the record endpoint. Any fields that are not included will not be updated.
     *
     * @throws \Assert\AssertionFailedException
     */
    public function updateRecord(string $table, array $criteria, array $fields): array
    {
        $record = $this->findRecord($table, $criteria);

        Assertion::notNull($record, 'Record not found');


        return $this->updateRecordById($table, $record->getId(), $fields);
    }

    /**
     * This will update some (but not all) fields of a table record,
     *  issuing a PATCH request to the record endpoint.
     *  Any fields that are not included will not be updated.
     *
     * @throws \Assert\AssertionFailedException
     */
    public function updateRecordById(string $table, string $id, array $fields): array
    {

        /** @var Response $response */
        $response = $this->browser->patch(
            $this->getEndpoint($table, $id),
            [
                'content-type' => 'application/json',
            ],
            json_encode([
                'fields' => $fields,
            ])
        );

        return $this->guardResponse($table, $response);
    }

    public function containsRecord(string $table, array $criteria): bool
    {
        return null !== $this->findRecord($table, $criteria);
    }

    public function flushRecords(string $table): void
    {
        $records = $this->findRecords($table);

        /** @var Record $record */
        foreach ($records as $record) {
            /** @var Response $response */
            $response = $this->browser->delete(
                $this->getEndpoint($table, $record->getId()),
                [
                    'content-type' => 'application/json',
                ]
            );

            $this->guardResponse($table, $response);
        }
    }

    public function deleteRecord(string $table, array $criteria): array
    {
        $record = $this->findRecord($table, $criteria);

        Assertion::notNull($record, 'Record not found');

        /** @var Response $response */
        $response = $this->browser->delete(
            $this->getEndpoint($table, $record->getId()),
            [
                'content-type' => 'application/json',
            ]
        );

        return $this->guardResponse($table, $response);
    }

    public function deleteRecords(string $table, array $criteria): void
    {
        $records = $this->findRecords($table, $criteria);
        foreach ($records as $record) {
            Assertion::notNull($record, 'Record not found');

            /** @var Response $response */
            $response = $this->browser->delete(
                $this->getEndpoint($table, $record->getId()),
                [
                'content-type' => 'application/json',
            ]
            );

            $this->guardResponse($table, $response);
        }
    }

    public function getRecord(string $table, string $id): Record
    {
        $url = $this->getEndpoint($table, $id);

        /** @var Response $response */
        $response = $this->browser->get(
            $url,
            [
                'content-type' => 'application/json',
            ]
        );

        $data = json_decode($response->getContent(), true);

        if (empty($data['id']) || empty($data['fields'])) {
            throw new \RuntimeException(sprintf("No records have been found from '%s:%s:%s'.", $this->base, $table, $id));
        }

        return new Record($data['id'], $data['fields']);
    }

    public function findRecord(string $table, array $criteria): ?Record
    {
        $records = $this->findRecords($table, $criteria);

        if (count($records) > 1) {
            throw new \RuntimeException(sprintf("More than one records have been found from '%s:%s'.", $this->base, $table));
        }

        if (0 === count($records)) {
            return null;
        }

        return current($records);
    }

    protected function format($s)
    {
        if (is_array($s)) {
            $res = [];
            foreach ($s as $key => $value) {
                $res[$this->format($key)] = $this->format($value);
            }

            return $res;
        }

        $s = str_replace(' ', '%20', $s);

        return $s;
    }

    /**
     * TODO - Be able to loop over multiple pages.
     *
     * @return Record[]
     */
    public function findRecords(string $table, array $criteria = []): array
    {
        $url = $this->getEndpoint($table);

        if (count($criteria) > 0) {
            $formulas = [];
            foreach ($criteria as $field => $value) {
                $field = $this->format($field);
                $formulas[] = sprintf("{%s}='%s'", $field, $value);
            }

            $url .= sprintf(
                '?filterByFormula=(%s)',
                implode(' AND ', $formulas)
            );
        }

        $offset = null;
        $start = true;
        $res = [];
        while ($start || $offset != null) {
            $start = false;
            $newUrl = $url;
            if (!empty($offset)) {
                if (count($criteria) > 0) {
                    $newUrl .= '&';
                } else {
                    $newUrl .= '?';
                }
                $newUrl .= '&offset=' . $offset;
            }


            /** @var Response $response */
            $response = $this->browser->get(
                $newUrl,
                [
                    'content-type' => 'application/json',
                ]
            );
            $data = json_decode($response->getContent(), true);

            if(empty($data['records'])){
                return [];
            }
            $offset = $data['offset'] ?? null;

            $result = array_map(function (array $value) {
                return new Record($value['id'], $value['fields']);
            }, $data['records']);

            $res = array_merge($res, $result);
        }

        return $res;
    }

    protected function getEndpoint(string $table, ?string $id = null): string
    {
        if ($id) {
            $urlPattern = 'https://api.airtable.com/v0/%BASE%/%TABLE%/%ID%';

            return strtr($urlPattern, [
                '%BASE%' => $this->base,
                '%TABLE%' => rawurlencode($table),
                '%ID%' => $id,
            ]);
        }

        $urlPattern = 'https://api.airtable.com/v0/%BASE%/%TABLE%';

        return strtr($urlPattern, [
            '%BASE%' => $this->base,
            '%TABLE%' => rawurlencode($table),
        ]);
    }

    protected function guardResponse(string $table, Response $response): array
    {
        if (429 === $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('Rate limit reach on "%s:%s".', $this->base, $table));
        }

        if (200 !== $response->getStatusCode()) {
            $content = json_decode($response->getContent(), true);
            $message = $content['error']['message'] ?? 'No details';

            throw new \RuntimeException(sprintf('An "%s" error occurred when trying to create record on "%s:%s" : %s', $response->getStatusCode(), $this->base, $table, $message));
        }


        if (empty($response->getContent())) {

            return [];
        }

        $content = json_decode($response->getContent(), true);

        return $content;
    }

    public function getBase()
    {
        $urlPattern = 'https://api.airtable.com/v0/meta/bases/%BASE%?include=collaborators&include=inviteLinks';

        $url = strtr($urlPattern, [
            '%BASE%' => $this->base,
        ]);

        /** @var Response $response */
        $response = $this->browser->get(
            $url,
            [
                'content-type' => 'application/json',
            ]
        );

        $data = json_decode($response->getContent(), true);

        return $data;
    }

     /**
     * Search Records 
     *
     * @param string $table
     * @param array $fields
     * @param string $search
     * @param string $criteria
     * @param integer $maxRows
     * @return Record[]
     */
    public function searchRecords(string $table, array $fields, string $search, string $criteria = "", string $view = "", int $maxRows = 5)
    {
        $url = $this->getEndpoint($table);
        $url .= sprintf(
            '?cellFormat=json&maxRecords=%s',
            $maxRows
        );

        if (!empty($view)) {
            $url .= sprintf(
                '&view=%s',
                rawurlencode($view)
            );
        }


        $searchString = '';
        if (count($fields) > 0) {
            $formulas = [];

            foreach ($fields as $field) {
                $formulas[] = sprintf("FIND(\"%s\",{%s})>0", $search, $field);
            }

            $searchString .= 'OR(' . rawurlencode(implode(',', $formulas)) . ')';
            //$url .= '&filterByFormula='.rawurlencode($formulas[0]).'';

        }

        if (!empty($criteria)) {

            $url .= '&filterByFormula=AND(' . rawurlencode($criteria) . ',' . $searchString . ')';
        } else {
            $url .= '&filterByFormula=' . $searchString;
        }




        $offset = null;
        $start = true;
        $res = [];
        while ($start || $offset != null) {
            $start = false;
            $newUrl = $url;
            if (!empty($offset)) {
                $newUrl .= '&offset=' . $offset;
            }


            /** @var Response $response */
            $response = $this->browser->get(
                $newUrl,
                [
                    'content-type' => 'application/json',
                ]
            );
            $data = json_decode($response->getContent(), true);
            if (empty($data['records'])) {
                return [];
            }
            $offset = $data['offset'] ?? null;

            $result = array_map(function (array $value) {
                return new Record($value['id'], $value['fields']);
            }, $data['records']);

            $res = array_merge($res, $result);
        }

        return $res;
    }
}
