<?php
namespace PHPDaemon\Clients\Mongo;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

class Cursor implements \Iterator {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/** @var mixed Cursor's ID */
	public $id;
	/** @var Collection's name */
	public $col;
	/** @var array Array of objects */
	public $items = [];
	/** @var mixed Current object */
	public $item;
	/** @var mixed Network connection */
	protected $conn;
	/** @var bool Is this cursor finished? */
	public $finished = false;
	/** @var bool Is this query failured? */
	public $failure = false;
	/** @var bool awaitCapable? */
	public $await = false;
	/** @var bool Is this cursor destroyed? */
	public $destroyed = false;
	/** @var bool */
	public $parseOplog = false;
	/** @var */
	public $tailable;
	/** @var */
	public $callback;

	public $counter = 0;

	protected $pos = 0;

	protected $keep = false;

	public function error() {
		return isset($this->items['$err']) ? $this->items['$err'] : false;
	}
	public function keep($bool = true) {
		$this->keep = (bool) $bool;
	}
	public function rewind() {
		reset($this->items);
	}
  
	public function current() {
		return isset($this->items[$this->pos]) ? $this->items[$this->pos] : null;
	}
  
	public function key() {
		return $this->pos;
	}
  
	public function next() {
		if ($this->keep) {
			++$this->pos;
		} else {
			array_shift($this->items);
		}
	}

	public function grab() {
		$items = $this->items;
		$this->items = [];
		return $items;
	}

	public function toArray() {
		$items = $this->items;
		$this->items = [];
		return $items;
	}
  
	public function valid() {
		$key = isset($this->items[$this->pos]) ? $this->items[$this->pos] :null;
		return ($key !== NULL && $key !== FALSE);
	}

	/**
	 * @TODO DESCR
	 * @return bool
	 */
	public function isBusyConn() {
		if (!$this->conn) {
			return false;
		}
		return $this->conn->isBusy();
	}

	/**
	 * @TODO DESCR
	 * @return mixed
	 */
	public function getConn() {
		return $this->conn;
	}

	/**
	 * @TODO DESCR
	 * @return bool
	 */
	public function isFinished() {
		return $this->finished;
	}

	/**
	 * Constructor
	 * @param string Cursor's ID
	 * @param string Collection's name
	 * @param object Network connection (MongoClientConnection),
	 * @param string $id
	 * @param Connection $conn
	 * @return void
	 */
	public function __construct($id, $col, $conn) {
		$this->id   = $id;
		$this->col  = $col;
		$this->conn = $conn;
	}

	/**
	 * Asks for more objects
	 * @param integer Number of objects
	 * @return void
	 */
	public function getMore($number = 0) {
		if ($this->finished || $this->destroyed) {
			return;
		}
		if (binarySubstr($this->id, 0, 1) === 'c') {
			$this->conn->pool->getMore($this->col, binarySubstr($this->id, 1), $number, $this->conn);
		}
	}

	public function isDead() {
		return $this->finished || $this->destroyed;
	}

	/**
	 * Destroys the cursors
	 * @return boolean Success
	 */
	public function destroy($notify = false) {
		if ($this->destroyed) {
			return false;
		}
		$this->destroyed = true;
		if ($notify) {
			if ($this->callback) {
				call_user_func($this->callback, $this);
			}
		}
		unset($this->conn->cursors[$this->id]);
		return true;
	}

	public function free($notify = false) {
		return $this->destroy($notify);
	}

	/**
	 * Cursor's destructor. Sends a signal to the server.
	 * @return void
	 */
	public function __destruct() {
		try {
			if (binarySubstr($this->id, 0, 1) === 'c') {
				$this->conn->pool->killCursors([binarySubstr($this->id, 1)], $this->conn);
			}
		} catch (ConnectionFinished $e) {}
	}
}
