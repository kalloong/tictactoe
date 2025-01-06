<?php
// server.php
require 'vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory;

class TicTacToeServer implements \Ratchet\MessageComponentInterface {
    private $clients;
    private $players;
    private $gameState;
    private $currentTurn;
    private $rematchVotes;
    private $playerSlots;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->players = [];
        $this->playerSlots = ['X' => null, 'O' => null];
        $this->resetGame();
    }

    private function resetGame() {
        $this->gameState = array_fill(0, 9, '');
        $this->currentTurn = 'X';
        $this->rematchVotes = ['X' => false, 'O' => false];
    }

    public function onOpen(\Ratchet\ConnectionInterface $conn) {
        $this->clients->attach($conn);
        
        // Check available slots
        $assignedSymbol = null;
        if ($this->playerSlots['X'] === null) {
            $this->playerSlots['X'] = $conn->resourceId;
            $assignedSymbol = 'X';
        } elseif ($this->playerSlots['O'] === null) {
            $this->playerSlots['O'] = $conn->resourceId;
            $assignedSymbol = 'O';
        }

        if ($assignedSymbol !== null) {
            $this->players[$conn->resourceId] = [
                'conn' => $conn,
                'symbol' => $assignedSymbol
            ];
            
            $conn->send(json_encode([
                'type' => 'connect',
                'symbol' => $assignedSymbol,
                'message' => "You are player $assignedSymbol"
            ]));

            if (count($this->players) === 2) {
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'start',
                        'turn' => 'X'
                    ]));
                }
            }
        } else {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Game room is full'
            ]));
            $conn->close();
        }
    }

    public function onMessage(\Ratchet\ConnectionInterface $from, $msg) {
        $data = json_decode($msg);
        
        if ($data->type === 'move') {
            if (isset($this->players[$from->resourceId]) && 
                $this->players[$from->resourceId]['symbol'] === $this->currentTurn) {
                
                if ($this->gameState[$data->position] === '') {
                    $this->gameState[$data->position] = $this->currentTurn;
                    
                    foreach ($this->players as $player) {
                        $player['conn']->send(json_encode([
                            'type' => 'move',
                            'position' => $data->position,
                            'symbol' => $this->currentTurn
                        ]));
                    }

                    if ($this->checkWin()) {
                        foreach ($this->players as $player) {
                            $player['conn']->send(json_encode([
                                'type' => 'gameOver',
                                'winner' => $this->currentTurn
                            ]));
                        }
                    } elseif ($this->checkDraw()) {
                        foreach ($this->players as $player) {
                            $player['conn']->send(json_encode([
                                'type' => 'gameOver',
                                'winner' => 'draw'
                            ]));
                        }
                    } else {
                        $this->currentTurn = ($this->currentTurn === 'X') ? 'O' : 'X';
                        foreach ($this->players as $player) {
                            $player['conn']->send(json_encode([
                                'type' => 'turn',
                                'turn' => $this->currentTurn
                            ]));
                        }
                    }
                }
            }
        } elseif ($data->type === 'rematchVote') {
            $playerSymbol = $this->players[$from->resourceId]['symbol'];
            $this->rematchVotes[$playerSymbol] = true;
            
            // Notify all players about the rematch vote
            foreach ($this->players as $player) {
                $player['conn']->send(json_encode([
                    'type' => 'rematchVoteUpdate',
                    'votes' => $this->rematchVotes
                ]));
            }

            // If both players voted for rematch
            if ($this->rematchVotes['X'] && $this->rematchVotes['O']) {
                $this->resetGame();
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'restart'
                    ]));
                }
            }
        }
    }

    public function onClose(\Ratchet\ConnectionInterface $conn) {
        // Find which symbol the disconnected player had
        if (isset($this->players[$conn->resourceId])) {
            $symbol = $this->players[$conn->resourceId]['symbol'];
            $this->playerSlots[$symbol] = null;
            unset($this->players[$conn->resourceId]);
            
            // Notify remaining player about disconnection
            foreach ($this->players as $player) {
                $player['conn']->send(json_encode([
                    'type' => 'playerDisconnected',
                    'message' => "Player $symbol has disconnected"
                ]));
            }
        }
        $this->clients->detach($conn);
    }

    public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }

    private function checkWin() {
        $winPatterns = [
            [0, 1, 2], [3, 4, 5], [6, 7, 8], // Rows
            [0, 3, 6], [1, 4, 7], [2, 5, 8], // Columns
            [0, 4, 8], [2, 4, 6] // Diagonals
        ];

        foreach ($winPatterns as $pattern) {
            if ($this->gameState[$pattern[0]] !== '' &&
                $this->gameState[$pattern[0]] === $this->gameState[$pattern[1]] &&
                $this->gameState[$pattern[1]] === $this->gameState[$pattern[2]]) {
                return true;
            }
        }
        return false;
    }

    private function checkDraw() {
        return !in_array('', $this->gameState);
    }
}

$loop = Factory::create();
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new TicTacToeServer()
        )
    ),
    8080
);

echo "Server running at ws://127.0.0.1:8080\n";
$server->run();