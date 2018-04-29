<?php

if (!function_exists('CheckOS')) {

    /**
     * @return bool
     */
    function CheckOS()
    {
        $os_string = php_uname('s');
        if (strpos(strtoupper($os_string), 'WIN') !== false) {
            return 'win';
        } else {
            return 'linux';
        }
    }
}

if (!function_exists('dd')) {
    /**
     * Dump the passed variables and end the script.
     *
     * @param  mixed
     * @return void
     */
    function dd()
    {
        array_map(function ($x) {
            (new App\Core\Support\Debug\Dumper())->dump($x);
        }, func_get_args());
        die(1);
    }
}