<?php

/*
 * Indonesian framework validation messages (i18n structural preparation S7). Source: the EN-ID dataset
 * docs/i18n/12-en-id-translation-dataset-final.csv, FRAMEWORK_VALIDATION_LOCALIZATION rows. Only the rules
 * the application uses are listed; any other rule falls back to English (app.fallback_locale).
 *
 * Canonical validation rules are unchanged: this file only changes how a failed rule is worded.
 */

return [

    'after' => 'Kolom :attribute harus berupa tanggal setelah :date.',
    'after_or_equal' => 'Kolom :attribute harus berupa tanggal yang sama dengan atau setelah :date.',
    'array' => 'Kolom :attribute harus berupa daftar.',
    'between' => [
        'numeric' => 'Kolom :attribute harus antara :min dan :max.',
    ],
    'boolean' => 'Kolom :attribute harus bernilai benar atau salah.',
    'current_password' => 'Kata sandi salah.',
    'date' => 'Kolom :attribute harus berupa tanggal yang valid.',
    'decimal' => 'Kolom :attribute harus memiliki :decimal angka desimal.',
    'email' => 'Kolom :attribute harus berupa alamat email yang valid.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'file' => 'Kolom :attribute harus berupa file.',
    'gt' => [
        'numeric' => 'Kolom :attribute harus lebih dari :value.',
    ],
    'in' => ':attribute yang dipilih tidak valid.',
    'integer' => 'Kolom :attribute harus berupa bilangan bulat.',
    'max' => [
        'array' => 'Kolom :attribute tidak boleh memiliki lebih dari :max item.',
        'file' => 'Kolom :attribute tidak boleh lebih dari :max kilobyte.',
        'numeric' => 'Kolom :attribute tidak boleh lebih dari :max.',
        'string' => 'Kolom :attribute tidak boleh lebih dari :max karakter.',
    ],
    'mimes' => 'Kolom :attribute harus berupa file dengan jenis: :values.',
    'min' => [
        'array' => 'Kolom :attribute minimal memiliki :min item.',
        'numeric' => 'Kolom :attribute minimal :min.',
        'string' => 'Kolom :attribute minimal :min karakter.',
    ],
    'not_in' => ':attribute yang dipilih tidak valid.',
    'numeric' => 'Kolom :attribute harus berupa angka.',
    'present' => 'Kolom :attribute harus ada.',
    'regex' => 'Format kolom :attribute tidak valid.',
    'required' => 'Kolom :attribute wajib diisi.',
    'required_if' => 'Kolom :attribute wajib diisi jika :other bernilai :value.',
    'required_with' => 'Kolom :attribute wajib diisi jika :values diisi.',
    'required_without' => 'Kolom :attribute wajib diisi jika :values tidak diisi.',
    'size' => [
        'array' => 'Kolom :attribute harus berisi :size item.',
        'numeric' => 'Kolom :attribute harus :size.',
        'string' => 'Kolom :attribute harus :size karakter.',
    ],
    'string' => 'Kolom :attribute harus berupa teks.',
    'unique' => ':attribute sudah digunakan.',
    'uuid' => 'Kolom :attribute harus berupa UUID yang valid.',

    /*
     * Field-specific wording ("validation.custom.<field>.<rule>") and display names for attributes
     * ("validation.attributes.<field>"). Empty until the rollout; without an entry Laravel derives
     * the name from the field key, as it does in English.
     */
    'custom' => [],

    'attributes' => [],

];
