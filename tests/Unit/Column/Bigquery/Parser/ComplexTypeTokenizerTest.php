<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Column\Bigquery\Parser;

use ArrayIterator;
use Generator;
use Keboola\TableBackendUtils\Column\Bigquery\Parser\ComplexTypeTokenizer;
use Keboola\TableBackendUtils\Column\Bigquery\Parser\Tokens\InternalToken;
use Keboola\TableBackendUtils\Column\Bigquery\Parser\Tokens\InternalTokenWithNested;
use PHPUnit\Framework\TestCase;

class ComplexTypeTokenizerTest extends TestCase
{
    public function definitions(): Generator
    {
        yield 'ARRAY<STRING>' => [
            'def' => 'col ARRAY<STRING>',
            'expected' => [
                ['type' => 'T_NAME', 'token' => 'col',],
                ['type' => 'T_TYPE', 'token' => 'ARRAY',],
                ['type' => 'T_NESTED', 'nested' => [['type' => 'T_TYPE', 'token' => 'STRING',],],],
            ],
        ];
        yield 'ARRAY<STRING(123245)>' => [
            'def' => 'col ARRAY<STRING(123245)>',
            'expected' => [
                ['type' => 'T_NAME', 'token' => 'col',],
                ['type' => 'T_TYPE', 'token' => 'ARRAY',],
                [
                    'type' => 'T_NESTED',
                    'nested' => [
                        ['type' => 'T_TYPE', 'token' => 'STRING',],
                        ['type' => 'T_LENGTH', 'token' => '123245',],
                    ],
                ],
            ],
        ];
        yield 'ARRAY<NUMERIC(10,10)>' => [
            'def' => 'ARRAY<NUMERIC(10,10)>',
            'expected' => [
                ['type' => 'T_TYPE', 'token' => 'ARRAY',],
                [
                    'type' => 'T_NESTED',
                    'nested' => [
                        ['type' => 'T_TYPE', 'token' => 'NUMERIC',],
                        ['type' => 'T_LENGTH', 'token' => '10,10',],
                    ],
                ],
            ],
        ];
        yield 'ARRAY<STRUCT<x NUMERIC>>' => [
            'def' => 'ARRAY<STRUCT<x NUMERIC>>',
            'expected' => [
                ['type' => 'T_TYPE', 'token' => 'ARRAY',],
                [
                    'type' => 'T_NESTED',
                    'nested' => [
                        ['type' => 'T_TYPE', 'token' => 'STRUCT',],
                        [
                            'type' => 'T_NESTED',
                            'nested' => [
                                ['type' => 'T_NAME', 'token' => 'x',],
                                ['type' => 'T_TYPE', 'token' => 'NUMERIC',],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        yield 'ARRAY<STRUCT<x NUMERIC(10,10)>>' => [
            'def' => 'ARRAY<STRUCT<x NUMERIC(10,10)>>',
            'expected' => [
                ['type' => 'T_TYPE', 'token' => 'ARRAY',],
                [
                    'type' => 'T_NESTED',
                    'nested' => [
                        ['type' => 'T_TYPE', 'token' => 'STRUCT',],
                        [
                            'type' => 'T_NESTED',
                            'nested' => [
                                ['type' => 'T_NAME', 'token' => 'x',],
                                ['type' => 'T_TYPE', 'token' => 'NUMERIC',],
                                ['type' => 'T_LENGTH', 'token' => '10,10',],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        //phpcs:ignore
        yield 'ARRAY<STRUCT<x NUMERIC(10,10),y ARRAY<NUMERIC(10,10)>, z STRUCT<x NUMERIC(10,10),y ARRAY<NUMERIC(10,10)>>>' => [
            //phpcs:ignore
            'def' => 'ARRAY<STRUCT<x NUMERIC(10,10),y ARRAY<NUMERIC(10,10)>, z STRUCT<x NUMERIC(10,10),y ARRAY<NUMERIC(10,10)>>>',
            'expected' => [
                ['type' => 'T_TYPE', 'token' => 'ARRAY',],
                [
                    'type' => 'T_NESTED',
                    'nested' => [
                        ['type' => 'T_TYPE', 'token' => 'STRUCT',],
                        [
                            'type' => 'T_NESTED',
                            'nested' => [
                                ['type' => 'T_NAME', 'token' => 'x',],
                                ['type' => 'T_TYPE', 'token' => 'NUMERIC',],
                                ['type' => 'T_LENGTH', 'token' => '10,10',],
                                ['type' => 'T_FIELD_DELIMITER', 'token' => ',',],
                                ['type' => 'T_NAME', 'token' => 'y',],
                                ['type' => 'T_TYPE', 'token' => 'ARRAY',],
                                [
                                    'type' => 'T_NESTED',
                                    'nested' => [
                                        ['type' => 'T_TYPE', 'token' => 'NUMERIC',],
                                        ['type' => 'T_LENGTH', 'token' => '10,10',],
                                    ],
                                ],
                                ['type' => 'T_FIELD_DELIMITER', 'token' => ',',],
                                ['type' => 'T_NAME', 'token' => 'z',],
                                ['type' => 'T_TYPE', 'token' => 'STRUCT',],
                                [
                                    'type' => 'T_NESTED',
                                    'nested' => [
                                        ['type' => 'T_NAME', 'token' => 'x',],
                                        ['type' => 'T_TYPE', 'token' => 'NUMERIC',],
                                        ['type' => 'T_LENGTH', 'token' => '10,10',],
                                        ['type' => 'T_FIELD_DELIMITER', 'token' => ',',],
                                        ['type' => 'T_NAME', 'token' => 'y',],
                                        ['type' => 'T_TYPE', 'token' => 'ARRAY',],
                                        [
                                            'type' => 'T_NESTED',
                                            'nested' => [
                                                ['type' => 'T_TYPE', 'token' => 'NUMERIC',],
                                                ['type' => 'T_LENGTH', 'token' => '10,10',],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        yield 'STRUCT<t NUMERIC(10,10)>' => [
            'def' => 'STRUCT<t NUMERIC(10,10)>',
            'expected' => [
                ['type' => 'T_TYPE', 'token' => 'STRUCT',],
                [
                    'type' => 'T_NESTED',
                    'nested' => [
                        ['type' => 'T_NAME', 'token' => 't',],
                        ['type' => 'T_TYPE', 'token' => 'NUMERIC',],
                        ['type' => 'T_LENGTH', 'token' => '10,10',],
                    ],
                ],
            ],
        ];
        yield 'col STRUCT<t NUMERIC(10,10)>' => [
            'def' => 'col STRUCT<t NUMERIC(10,10)>',
            'expected' => [
                ['type' => 'T_NAME', 'token' => 'col',],
                ['type' => 'T_TYPE', 'token' => 'STRUCT',],
                [
                    'type' => 'T_NESTED',
                    'nested' => [
                        ['type' => 'T_NAME', 'token' => 't',],
                        ['type' => 'T_TYPE', 'token' => 'NUMERIC',],
                        ['type' => 'T_LENGTH', 'token' => '10,10',],
                    ],
                ],
            ],
        ];
        yield 'STRUCT<t NUMERIC(10)>' => [
            'def' => 'STRUCT<t NUMERIC(10)>',
            'expected' => [
                ['type' => 'T_TYPE', 'token' => 'STRUCT',],
                [
                    'type' => 'T_NESTED',
                    'nested' => [
                        ['type' => 'T_NAME', 'token' => 't',],
                        ['type' => 'T_TYPE', 'token' => 'NUMERIC',],
                        ['type' => 'T_LENGTH', 'token' => '10',],
                    ],
                ],
            ],
        ];
        yield 'STRUCT<t NUMERIC>' => [
            'def' => 'STRUCT<t NUMERIC>',
            'expected' => [
                ['type' => 'T_TYPE', 'token' => 'STRUCT',],
                [
                    'type' => 'T_NESTED',
                    'nested' => [['type' => 'T_NAME', 'token' => 't',], ['type' => 'T_TYPE', 'token' => 'NUMERIC',],],
                ],
            ],
        ];

        yield 'STRUCT<x STRUCT<y INTEGER,z INTEGER>>' => [
            'def' => 'STRUCT<x STRUCT<y INTEGER,z INTEGER>>',
            'expected' => [
                ['type' => 'T_TYPE', 'token' => 'STRUCT',],
                [
                    'type' => 'T_NESTED',
                    'nested' => [
                        ['type' => 'T_NAME', 'token' => 'x',],
                        ['type' => 'T_TYPE', 'token' => 'STRUCT',],
                        [
                            'type' => 'T_NESTED',
                            'nested' => [
                                ['type' => 'T_NAME', 'token' => 'y',],
                                ['type' => 'T_TYPE', 'token' => 'INTEGER',],
                                ['type' => 'T_FIELD_DELIMITER', 'token' => ','],
                                ['type' => 'T_NAME', 'token' => 'z',],
                                ['type' => 'T_TYPE', 'token' => 'INTEGER',],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider definitions
     * @param array<mixed> $expected
     */
    public function test(string $def, array $expected): void
    {
        $tokens = (new ComplexTypeTokenizer())->tokenize($def);
        $this->assertSame($expected, $this->recursiveIteratorToArray($tokens));
    }

    /**
     * @param ArrayIterator<int, InternalToken|InternalTokenWithNested> $tokens
     * @return array<mixed>
     */
    private function recursiveIteratorToArray(ArrayIterator $tokens): array
    {
        $result = [];
        foreach ($tokens as $token) {
            $result [] = $this->tokenToArray($token);
        }
        return $result;
    }

    /**
     * @return array<mixed>
     */
    private function tokenToArray(InternalToken|InternalTokenWithNested $token): array
    {
        $res = [
            'type' => $token->type,
        ];
        if ($token instanceof InternalTokenWithNested) {
            $res['nested'] = $this->recursiveIteratorToArray($token->nested);
        } else {
            $res['token'] = $token->token;
        }
        return $res;
    }
}
