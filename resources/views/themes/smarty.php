<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (see LICENSE.txt in the base directory.  If
 * not, see:
 *
 * @link <http://www.gnu.org/licenses/>.
 * @author niel
 * @edit DariusIII
 * @copyright 2015 nZEDb/NNTmux
 */
require_once dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'autoload.php';

if (function_exists('ini_set') && function_exists('ini_get')) {
    ini_set('include_path', NN_WWW.PATH_SEPARATOR.ini_get('include_path'));
}

$www_top = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
if (strlen($www_top) === 1) {
    $www_top = '';
}

// Used everywhere an href is output, includes the full path to the NNTmux install.
define('WWW_TOP', $www_top);
define('WWW_THEMES', '/themes');
define('WWW_ASSETS', '/assets');
