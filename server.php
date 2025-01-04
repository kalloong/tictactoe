<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

class TicTacToeServer implements MessageComponentInterface {
    protected $clients;
    private $board;
    private $turn;
    private $playerMap;
    private $gameStarted;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->board = array_fill(0, 9, null);
        $this->turn = 0;
        $this->playerMap = [];
        $this->gameStarted = false; // Track if the game has started
    }

    public function onOpen(ConnectionInterface $conn) {
        if ($this->clients->count() >= 2) {
            // Allow third player to connect but don't start the game
            $conn->send(json_encode(['message' => 'Spectator connected, game in progress.']));
        } else {
            $this->clients->attach($conn);
            $playerId = $this->clients->count() - 1;
            $this->playerMap[$conn->resourceId] = $playerId;

            $conn->send(json_encode(['message' => 'Welcome!', 'player' => $playerId]));
            echo "New connection: {$conn->resourceId} (Player {$playerId})\n";

            if ($this->clients->count() === 2) {
                $this->gameStarted = true; // Game starts when two players are connected
                $this->broadcast(['message' => 'Game Start', 'board' => $this->board, 'turn' => $this->turn]);
            }
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $player = $this->playerMap[$from->resourceId] ?? null;

        if ($player !== $this->turn) {
            $from->send(json_encode(['error' => 'Not your turn!']));
            return;
        }

        $index = (int)$msg;
        if ($this->board[$index] !== null || $index < 0 || $index > 8) {
            $from->send(json_encode(['error' => 'Invalid move!']));
            return;
        }

        $this->board[$index] = $player;
        $this->turn = 1 - $this->turn;

        $this->broadcast(['board' => $this->board, 'turn' => $this->turn]);

        if ($this->checkWin($player)) {
            $this->broadcast(['message' => "Player {$player} wins!"]);
            $this->gameStarted = false; // End game
        } elseif (!in_array(null, $this->board)) {
            $this->broadcast(['message' => 'Draw!']);
            $this->gameStarted = false; // End game
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";

        if ($this->clients->count() == 1 && $this->gameStarted) {
            // Keep the game going with just 1 player, do not reset
            $this->broadcast(['message' => 'One player disconnected, the game continues with the remaining player.']);
        } else {
            $this->broadcast(['message' => 'Player disconnected, resetting game.']);
            $this->resetGame();
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    private function broadcast($data) {
        foreach ($this->clients as $client) {
            $client->send(json_encode($data));
        }
    }

    private function resetGame() {
        $this->board = array_fill(0, 9, null);
        $this->turn = 0;
        $this->broadcast(['board' => $this->board, 'turn' => $this->turn]);
        $this->gameStarted = false;
    }

    private function checkWin($player) {
        $winningCombos = [
            [0, 1, 2], [3, 4, 5], [6, 7, 8], // Rows
            [0, 3, 6], [1, 4, 7], [2, 5, 8], // Columns
            [0, 4, 8], [2, 4, 6]            // Diagonals
        ];

        foreach ($winningCombos as $combo) {
            if ($this->board[$combo[0]] === $player && 
                $this->board[$combo[1]] === $player && 
                $this->board[$combo[2]] === $player) {
                return true;
            }
        }

        return false;
    }
}

// Start server
$loop = Loop::get();
$socket = new SocketServer('0.0.0.0:8080', [], $loop);
$server = new Ratchet\Server\IoServer(
    new HttpServer(
        new WsServer(
            new TicTacToeServer()
        )
    ),
    $socket,
    $loop
);

echo "WebSocket server running at ws://127.0.0.1:8080\n";
$loop->run();
