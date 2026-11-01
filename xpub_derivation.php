<?php
// Pure Native PHP BIP84 (Native Segwit Bech32) Address Derivation using GMP

class BIP84Deriver {
    private static $P = null;
    private static $N = null;
    private static $Gx = null;
    private static $Gy = null;
    private static $B58_CHARS = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    private static $BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    private static function init() {
        if (self::$P === null) {
            self::$P = gmp_init('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F', 16);
            self::$N = gmp_init('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BB5BF56708317E5D3', 16);
            self::$Gx = gmp_init('79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798', 16);
            self::$Gy = gmp_init('483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8', 16);
        }
    }

    private static function modinv($a, $m) {
        return gmp_invert($a, $m);
    }

    private static function point_add($p1, $p2) {
        if ($p1 === null) return $p2;
        if ($p2 === null) return $p1;
        $x1 = $p1[0]; $y1 = $p1[1];
        $x2 = $p2[0]; $y2 = $p2[1];

        if (gmp_cmp($x1, $x2) === 0) {
            if (gmp_cmp(gmp_mod(gmp_add($y1, $y2), self::$P), 0) === 0) {
                return null;
            }
            $num = gmp_mul(3, gmp_powm($x1, 2, self::$P));
            $den = gmp_mul(2, $y1);
            $m = gmp_mod(gmp_mul($num, self::modinv($den, self::$P)), self::$P);
        } else {
            $num = gmp_sub($y2, $y1);
            $den = gmp_sub($x2, $x1);
            $m = gmp_mod(gmp_mul($num, self::modinv($den, self::$P)), self::$P);
        }

        $x3 = gmp_mod(gmp_sub(gmp_sub(gmp_powm($m, 2, self::$P), $x1), $x2), self::$P);
        $y3 = gmp_mod(gmp_sub(gmp_mul($m, gmp_sub($x1, $x3)), $y1), self::$P);

        $x3 = gmp_mod(gmp_add($x3, self::$P), self::$P);
        $y3 = gmp_mod(gmp_add($y3, self::$P), self::$P);

        return [$x3, $y3];
    }

    private static function point_mul($k, $point = null) {
        self::init();
        if ($point === null) {
            $point = [self::$Gx, self::$Gy];
        }
        $res = null;
        $cur = $point;
        $k_gmp = gmp_init($k);

        while (gmp_cmp($k_gmp, 0) > 0) {
            if (gmp_testbit($k_gmp, 0)) {
                $res = self::point_add($res, $cur);
            }
            $cur = self::point_add($cur, $cur);
            $k_gmp = gmp_div_q($k_gmp, 2);
        }
        return $res;
    }

    private static function decompress_pubkey($pk_bin) {
        self::init();
        $prefix = ord($pk_bin[0]);
        $x_hex = bin2hex(substr($pk_bin, 1, 32));
        $x = gmp_init($x_hex, 16);

        $y_sq = gmp_mod(gmp_add(gmp_powm($x, 3, self::$P), 7), self::$P);
        $exp = gmp_div_q(gmp_add(self::$P, 1), 4);
        $y = gmp_powm($y_sq, $exp, self::$P);

        $y_is_even = gmp_testbit($y, 0) === false;
        if (($prefix === 2 && !$y_is_even) || ($prefix === 3 && $y_is_even)) {
            $y = gmp_sub(self::$P, $y);
        }
        return [$x, $y];
    }

    private static function compress_pubkey($pt) {
        $x = $pt[0];
        $y = $pt[1];
        $prefix = (gmp_testbit($y, 0) === false) ? "\x02" : "\x03";
        $x_hex = str_pad(gmp_strval($x, 16), 64, '0', STR_PAD_LEFT);
        return $prefix . hex2bin($x_hex);
    }

    public static function b58decode($s) {
        $n = gmp_init(0);
        for ($i = 0; $i < strlen($s); $i++) {
            $char = $s[$i];
            $pos = strpos(self::$B58_CHARS, $char);
            if ($pos === false) return false;
            $n = gmp_add(gmp_mul($n, 58), $pos);
        }
        $hex = gmp_strval($n, 16);
        if (strlen($hex) % 2 !== 0) $hex = '0' . $hex;
        $bin = hex2bin($hex);
        for ($i = 0; $i < strlen($s) && $s[$i] === '1'; $i++) {
            $bin = "\x00" . $bin;
        }
        return substr($bin, 4, 74);
    }

    public static function ckd_pub($parent_pub_bin, $parent_cc_bin, $index) {
        self::init();
        $idx_bin = pack('N', $index);
        $data = $parent_pub_bin . $idx_bin;
        $hash = hash_hmac('sha512', $data, $parent_cc_bin, true);
        $Il = substr($hash, 0, 32);
        $Ir = substr($hash, 32, 32);

        $il_gmp = gmp_init(bin2hex($Il), 16);
        if (gmp_cmp($il_gmp, self::$N) >= 0) {
            throw new Exception("Scalar overflow");
        }

        $P_il = self::point_mul($il_gmp);
        $P_parent = self::decompress_pubkey($parent_pub_bin);
        $P_child = self::point_add($P_parent, $P_il);

        return [self::compress_pubkey($P_child), $Ir];
    }

    private static function bech32_polymod($values) {
        $chk = 1;
        $generator = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        foreach ($values as $value) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $value;
            for ($i = 0; $i < 5; ++$i) {
                $chk ^= (($top >> $i) & 1) ? $generator[$i] : 0;
            }
        }
        return $chk;
    }

    private static function bech32_hrp_expand($hrp) {
        $ret = [];
        for ($i = 0; $i < strlen($hrp); ++$i) {
            $ret[] = ord($hrp[$i]) >> 5;
        }
        $ret[] = 0;
        for ($i = 0; $i < strlen($hrp); ++$i) {
            $ret[] = ord($hrp[$i]) & 31;
        }
        return $ret;
    }

    private static function convertbits($data, $frombits, $tobits, $pad = true) {
        $acc = 0;
        $bits = 0;
        $ret = [];
        $maxv = (1 << $tobits) - 1;
        $len = count($data);
        for ($i = 0; $i < $len; ++$i) {
            $value = $data[$i];
            $acc = ($acc << $frombits) | $value;
            $bits += $frombits;
            while ($bits >= $tobits) {
                $bits -= $tobits;
                $ret[] = ($acc >> $bits) & $maxv;
            }
        }
        if ($pad && $bits) {
            $ret[] = ($acc << ($tobits - $bits)) & $maxv;
        }
        return $ret;
    }

    public static function pubkey_to_bech32($pubkey_bin, $hrp = 'bc') {
        $sha = hash('sha256', $pubkey_bin, true);
        $h160 = hash('ripemd160', $sha, true);
        $data_bytes = array_values(unpack('C*', $h160));
        $data_5bit = array_merge([0], self::convertbits($data_bytes, 8, 5, true));

        $expanded = self::bech32_hrp_expand($hrp);
        $values = array_merge($expanded, $data_5bit, [0, 0, 0, 0, 0, 0]);
        $polymod = self::bech32_polymod($values) ^ 1;
        $checksum = [];
        for ($i = 0; $i < 6; ++$i) {
            $checksum[] = ($polymod >> 5 * (5 - $i)) & 31;
        }

        $combined = array_merge($data_5bit, $checksum);
        $addr = $hrp . '1';
        foreach ($combined as $val) {
            $addr .= self::$BECH32_CHARSET[$val];
        }
        return $addr;
    }

    public static function derive_address($xpub, $index = 0, $change = 0) {
        $raw = self::b58decode($xpub);
        if (!$raw || strlen($raw) !== 74) {
            throw new Exception("Invalid xpub key length");
        }
        $chain_code = substr($raw, 9, 32);
        $pubkey = substr($raw, 41, 33);

        // Derive branch: /0 for receive
        list($branch_pub, $branch_cc) = self::ckd_pub($pubkey, $chain_code, $change);
        // Derive address index: /0/index
        list($child_pub, $child_cc) = self::ckd_pub($branch_pub, $branch_cc, $index);

        return self::pubkey_to_bech32($child_pub, 'bc');
    }
}
