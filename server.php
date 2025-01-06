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
    private $rematchTimer;
    private $rematchTimeout = 30; // 30 seconds
    private $waitingForRematch = null; // Store player waiting for rematch
    private $lastRematchVoter = null;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->players = [];
        $this->playerSlots = ['X' => null, 'O' => null];
        $this->resetGame();
        $this->rematchTimer = null;
        $this->waitingForRematch = null; // Reset waiting state
        $this->lastRematchVoter = null;  // Reset last voter
    }

    private function resetGame() {
        $this->gameState = array_fill(0, 9, '');
        $this->currentTurn = 'X';
        $this->rematchVotes = ['X' => false, 'O' => false];
    }

    public function onOpen(\Ratchet\ConnectionInterface $conn) {
        $this->clients->attach($conn);
        
        if ($this->waitingForRematch !== null) {
            // Assign new player opposite to waiting player
            $waitingPlayer = $this->players[$this->waitingForRematch];
            $newSymbol = ($waitingPlayer['symbol'] === 'X') ? 'O' : 'X';
            $this->playerSlots[$newSymbol] = $conn->resourceId;
            $assignedSymbol = $newSymbol;
        } else {
            // Random assignment for new game
            $assignedSymbol = null;
            if (empty($this->players)) {
                $randomSymbol = (rand(0, 1) === 0) ? 'X' : 'O';
                $this->playerSlots[$randomSymbol] = $conn->resourceId;
                $assignedSymbol = $randomSymbol;
            } else {
                $existingSymbol = reset($this->players)['symbol'];
                $availableSymbol = ($existingSymbol === 'X') ? 'O' : 'X';
                $this->playerSlots[$availableSymbol] = $conn->resourceId;
                $assignedSymbol = $availableSymbol;
            }
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
                $this->waitingForRematch = null;
                $this->resetGame();
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'start',
                        'turn' => $this->currentTurn
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
            // Reset last rematch voter if this is a new game session
            if ($this->lastRematchVoter !== null && !isset($this->players[$this->lastRematchVoter])) {
                $this->lastRematchVoter = null;
            }
            
            $playerSymbol = $this->players[$from->resourceId]['symbol'];
            $this->rematchVotes[$playerSymbol] = true;
            $this->lastRematchVoter = $from->resourceId;
            
            // Start timer if first vote
            if ($this->rematchTimer === null) {
                $this->rematchTimer = time();
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'rematchTimerStart',
                        'timeout' => $this->rematchTimeout
                    ]));
                }
            }
            
            // Check time elapsed
            $timeElapsed = time() - $this->rematchTimer;
            if ($timeElapsed > $this->rematchTimeout) {
                // Time expired - handle timeout
                $this->handleRematchTimeout($playerSymbol);
                return;
            }
        
            foreach ($this->players as $player) {
                $player['conn']->send(json_encode([
                    'type' => 'rematchVoteUpdate',
                    'votes' => $this->rematchVotes,
                    'timeLeft' => $this->rematchTimeout - $timeElapsed
                ]));
            }
        
            // If both voted
            if ($this->rematchVotes['X'] && $this->rematchVotes['O']) {
                $this->rematchTimer = null;
                $this->lastRematchVoter = null; // Reset last voter on successful rematch
                $this->resetGame();
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'stopRematchTimer'
                    ]));
                }
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'restart',
                        'turn' => $this->currentTurn
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
            
            // Reset the game state
            $this->resetGame();
            
            // Notify remaining player about disconnection and reset
            foreach ($this->players as $player) {
                $player['conn']->send(json_encode([
                    'type' => 'playerDisconnected',
                    'message' => "Player $symbol has disconnected",
                    'reset' => true
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

    private function handleRematchTimeout($votedSymbol) {
        // Disconnect non-voting player
        foreach ($this->players as $resourceId => $player) {
            if ($player['symbol'] !== $votedSymbol) {
                // Send message before closing
                $player['conn']->send(json_encode([
                    'type' => 'timeoutDisconnect',
                    'message' => 'You have been disconnected due to rematch timeout'
                ]));
                $player['conn']->close();
                unset($this->players[$resourceId]);
                $this->playerSlots[$player['symbol']] = null;
            } else {
                $this->waitingForRematch = $resourceId;
                // Update remaining player status
                $player['conn']->send(json_encode([
                    'type' => 'waitingForNewPlayer',
                    'message' => 'Opponent disconnected. Waiting for new opponent...'
                ]));
            }
        }
        $this->rematchTimer = null;
        $this->rematchVotes = ['X' => false, 'O' => false];
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