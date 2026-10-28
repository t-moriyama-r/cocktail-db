<?php

namespace App\Services\Recipe;

use RuntimeException;

/**
 * StrengthCalculator で、度数を計算できる材料が無かったときの例外。
 */
final class StrengthNotCalculableException extends RuntimeException {}
