<?php

namespace App\Enums;

enum DrugClass: string
{
    case OverTheCounter = 'bebas';
    case LimitedOverTheCounter = 'bebas_terbatas';
    case Prescription = 'keras';
    case Psychotropic = 'psikotropika';
    case Narcotic = 'narkotika';
    case MedicalDevice = 'alkes';
    case Supplement = 'suplemen';
    case Cosmetic = 'kosmetik';
    case Other = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::OverTheCounter => 'Obat Bebas',
            self::LimitedOverTheCounter => 'Obat Bebas Terbatas',
            self::Prescription => 'Obat Keras',
            self::Psychotropic => 'Psikotropika',
            self::Narcotic => 'Narkotika',
            self::MedicalDevice => 'Alat Kesehatan',
            self::Supplement => 'Suplemen',
            self::Cosmetic => 'Kosmetik',
            self::Other => 'Lainnya',
        };
    }

    /**
     * Warna lingkaran pada kemasan: hijau bebas, biru bebas terbatas, merah keras ke atas.
     */
    public function color(): string
    {
        return match ($this) {
            self::OverTheCounter => 'emerald',
            self::LimitedOverTheCounter => 'sky',
            self::Prescription, self::Psychotropic, self::Narcotic => 'rose',
            default => 'slate',
        };
    }

    public function requiresPrescriptionByDefault(): bool
    {
        return in_array($this, [self::Prescription, self::Psychotropic, self::Narcotic], true);
    }

    /**
     * Golongan yang pelaporannya (SIPNAP) di luar cakupan aplikasi, jadi diblokir dari kasir kecuali dibuka pemilik.
     */
    public function isControlled(): bool
    {
        return in_array($this, [self::Psychotropic, self::Narcotic], true);
    }
}
