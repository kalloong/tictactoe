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
    private $chatHistory = []; // add protocol chat
    private $gameHistory = []; // add protocol history game
    private $turnTimer = null;
    private $turnTimeout = 5; // 5 seconds for each turn

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
        $this->chatHistory = [];
        $this->turnTimer = time();
        
        // Send initial turn with timeout to all players
        foreach ($this->players as $player) {
            $player['conn']->send(json_encode([
                'type' => 'turn',
                'turn' => $this->currentTurn,
                'timeout' => $this->turnTimeout
            ]));
        }
    }

    public function onOpen(\Ratchet\ConnectionInterface $conn) {
        // If both player slots are already filled, immediately close the connection
        if ($this->playerSlots['X'] !== null && $this->playerSlots['O'] !== null) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Game room is full'
            ]));
            $conn->close();
            return;
        }
    
        $this->clients->attach($conn);
        
        // Determine available symbol and handle connection
        $assignedSymbol = null;
        
        // Check if there's a player waiting for a rematch
        if ($this->waitingForRematch !== null) {
            $waitingPlayer = $this->players[$this->waitingForRematch];
            $assignedSymbol = ($waitingPlayer['symbol'] === 'X') ? 'O' : 'X';
            $this->playerSlots[$assignedSymbol] = $conn->resourceId;
            
            // Reset waiting state
            $this->waitingForRematch = null;
        } else {
            // Assign symbol based on current game state
            if ($this->playerSlots['X'] === null) {
                $assignedSymbol = 'X';
                $this->playerSlots['X'] = $conn->resourceId;
            } elseif ($this->playerSlots['O'] === null) {
                $assignedSymbol = 'O';
                $this->playerSlots['O'] = $conn->resourceId;
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
    
            // If two players are now connected, start the game
            if (count($this->players) === 2) {
                // Reset rematch-related states
                $this->rematchVotes = ['X' => false, 'O' => false];
                $this->rematchTimer = null;
                $this->lastRematchVoter = null;
                $this->waitingForRematch = null;
    
                // For the first game, start automatically
                $this->resetGame();
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'start',
                        'turn' => $this->currentTurn,
                        'isFirstGame' => true // Add a flag for first game
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

        // add protocol chat history
        if (isset($this->players[$conn->resourceId])) {
            foreach ($this->chatHistory as $chatMsg) {
                $conn->send(json_encode($chatMsg));
            }
        }
    }

    public function onMessage(\Ratchet\ConnectionInterface $from, $msg) {
        $data = json_decode($msg);
        
        // added protocol chat
        // Handle chat messages
        if ($data->type === 'chat') {
            if (isset($this->players[$from->resourceId])) {
                $playerSymbol = $this->players[$from->resourceId]['symbol'];
                $chatMessage = [
                    'type' => 'chat',
                    'player' => $playerSymbol,
                    'message' => $data->message,
                    'timestamp' => date('H:i:s')
                ];
                
                $this->chatHistory[] = $chatMessage;
                
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode($chatMessage));
                }
            }
            return;
        }

        //added protocol timeout turn
        if ($data->type === 'turnTimeout') {
            if (isset($this->players[$from->resourceId])) {
                $timeoutPlayer = $this->players[$from->resourceId]['symbol'];
                $winner = ($timeoutPlayer === 'X') ? 'O' : 'X';
                
                // Add timeout game to history
                $this->addGameToHistory($winner, 'timeout');
                
                // End the game and notify players
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'gameOver',
                        'winner' => $winner,
                        'reason' => 'timeout',
                        'timeoutPlayer' => $timeoutPlayer,
                        'gameHistory' => $this->gameHistory
                    ]));
                }
                
                // Reset game state
                $this->gameState = array_fill(0, 9, '');
                $this->turnTimer = null;
                $gameActive = false;
                
                return;
            }
        }

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
                        $this->turnTimer = null; // Reset timer
                        // Add game result to history
                        $this->addGameToHistory($this->currentTurn);
                        
                        foreach ($this->players as $player) {
                            $player['conn']->send(json_encode([
                                'type' => 'gameOver',
                                'winner' => $this->currentTurn,
                                'gameHistory' => $this->gameHistory
                            ]));
                        }
                    } elseif ($this->checkDraw()) {
                        $this->turnTimer = null; // Reset timer
                        // Add draw to history
                        $this->addGameToHistory('draw');
                        
                        foreach ($this->players as $player) {
                            $player['conn']->send(json_encode([
                                'type' => 'gameOver',
                                'winner' => 'draw',
                                'gameHistory' => $this->gameHistory
                            ]));
                        }
                    } else {
                        $this->currentTurn = ($this->currentTurn === 'X') ? 'O' : 'X';
                        $this->turnTimer = time(); // Reset turn timer for next player
                        foreach ($this->players as $player) {
                            $player['conn']->send(json_encode([
                                'type' => 'turn',
                                'turn' => $this->currentTurn,
                                'timeout' => $this->turnTimeout
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
                
                // Send stop timer message to both players
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'stopRematchTimer'
                    ]));
                }
                
                // Send restart message only after both players have voted
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
            
            // Clear chat history when a player disconnects
            $this->chatHistory = [];
            $this->gameHistory = []; // Clear game history
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

    // add protocol history game
    private function addGameToHistory($result, $reason = 'normal') {
        $this->gameHistory[] = [
            'result' => $result,
            'reason' => $reason,
            'timestamp' => date('H:i:s')
        ];
    }
    

    // add protocol Timer Turn
    private function checkTurnTimeout() {
        if ($this->turnTimer !== null) {
            $timeElapsed = time() - $this->turnTimer;
            if ($timeElapsed >= $this->turnTimeout) {
                // Current player loses due to timeout
                $loser = $this->currentTurn;
                $winner = ($loser === 'X') ? 'O' : 'X';
                
                // Add game to history with timeout winner
                $this->addGameToHistory($winner);
                
                // Notify players about timeout and game result
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'gameOver',
                        'winner' => $winner,
                        'reason' => 'timeout',
                        'gameHistory' => $this->gameHistory
                    ]));
                }
                
                return true;
            }
        }
        return false;
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
        // Store the player who voted
        $votingPlayerResourceId = null;
        
        // Disconnect non-voting player
        foreach ($this->players as $resourceId => $player) {
            if ($player['symbol'] === $votedSymbol) {
                $votingPlayerResourceId = $resourceId;
                $this->waitingForRematch = $resourceId;
                
                // Update remaining player status
                $player['conn']->send(json_encode([
                    'type' => 'waitingForNewPlayer',
                    'message' => 'Opponent disconnected. Waiting for new opponent...'
                ]));
            } else {
                // Send message before closing
                $player['conn']->send(json_encode([
                    'type' => 'timeoutDisconnect',
                    'message' => 'You have been disconnected due to rematch timeout'
                ]));
                $player['conn']->close();
                unset($this->players[$resourceId]);
                $this->playerSlots[$player['symbol']] = null;
            }
        }
        
        // Reset rematch-related states
        $this->rematchTimer = null;
        $this->rematchVotes = ['X' => false, 'O' => false];
        $this->lastRematchVoter = null;
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