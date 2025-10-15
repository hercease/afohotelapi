<?php
class Database {
    private $host = DB_HOST;
    private $user = DB_USER;
    private $pass = DB_PASS;
    private $dbname = DB_NAME;
    private $dbport = DB_PORT;
    private $conn;

    public function connect() {
        $this->conn = new mysqli($this->host, $this->user, $this->pass, $this->dbname, $this->dbport);

        if ($this->conn->connect_error) {
            die("Connection failed: " . $this->conn->connect_error);
        }

        return $this->conn;
    }
}

?>