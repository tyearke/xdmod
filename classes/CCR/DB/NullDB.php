<?php
/**
 * Null database implementation.
 *
 * @author Jeffrey T. Palmer <jtpalmer@ccr.buffalo.edu>
 *
 * Changelog
 *
 * 2015-12-15 Steve Gallo <smgallo@buffalo.edu>
 * - Added prepare()
 */

namespace CCR\DB;

class NullDB implements iDatabase
{
    public function connect()
    {
    }

    public function destroy()
    {
    }

    public function insert($statement, $params = array())
    {
        return 0;
    }

    public function query(
        $query,
        array $params = array(),
        $returnStatement = false
    ) {
        return array();
    }

    public function execute($query, array $params = array())
    {
        return 0;
    }

    public function prepare($query)
    {
        return FALSE;
    }

    public function beginTransaction()
    {
        return true;
    }

    public function commit()
    {
        return true;
    }

    public function rollBack()
    {
        return true;
    }

    public function quote($string)
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }
}
