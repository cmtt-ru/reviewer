<?php
namespace TJ;

interface IStorage {
    public function sadd();
    public function sismember();
    public function set();
    public function exists();
}