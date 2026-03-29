<?php

namespace App\Domain\DocEase;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DocEaseReadModel
{
    /**
     * @return array{
     *   connected: bool,
     *   error: ?string,
     *   stats: array<string, int|null>,
     *   timestamps: array<string, string|null>
     * }
     */
    public function snapshot(): array
    {
        try {
            DB::connection('doc_ease')->getPdo();
        } catch (Throwable $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
                'stats' => [
                    'users_total' => null,
                    'admins_total' => null,
                    'teachers_total' => null,
                    'students_total' => null,
                    'uploaded_files_total' => null,
                    'attendance_attachments_total' => null,
                    'learning_material_files_total' => null,
                    'class_records_total' => null,
                    'subjects_total' => null,
                    'sections_total' => null,
                ],
                'timestamps' => [
                    'uploaded_files_latest' => null,
                ],
            ];
        }

        $uploadedFilesTotal = $this->safeCount('uploaded_files', static function ($q): void {
            $q->where(static function ($w): void {
                $w->where('is_deleted', 0)->orWhereNull('is_deleted');
            });
        });

        return [
            'connected' => true,
            'error' => null,
            'stats' => [
                'users_total' => $this->safeCount('users'),
                'admins_total' => $this->safeCount('users', static function ($q): void {
                    $q->where('role', 'admin');
                }),
                'teachers_total' => $this->safeCount('users', static function ($q): void {
                    $q->where('role', 'teacher');
                }),
                'students_total' => $this->safeCount('users', static function ($q): void {
                    $q->whereIn('role', ['student', 'user']);
                }),
                'uploaded_files_total' => $uploadedFilesTotal,
                'attendance_attachments_total' => $this->safeCount('attendance_attachments'),
                'learning_material_files_total' => $this->safeCount('learning_material_files'),
                'class_records_total' => $this->safeCount('class_records'),
                'subjects_total' => $this->safeCount('subjects'),
                'sections_total' => $this->safeCount('sections'),
            ],
            'timestamps' => [
                'uploaded_files_latest' => $this->latestUploadedFileTimestamp(),
            ],
        ];
    }

    private function latestUploadedFileTimestamp(): ?string
    {
        if (!$this->hasTable('uploaded_files')) {
            return null;
        }

        try {
            $query = DB::connection('doc_ease')->table('uploaded_files')
                ->where(static function ($w): void {
                    $w->where('is_deleted', 0)->orWhereNull('is_deleted');
                });

            $uploadDate = $query->max('upload_date');
            if (is_string($uploadDate) && trim($uploadDate) !== '') {
                return trim($uploadDate);
            }

            $createdAt = DB::connection('doc_ease')->table('uploaded_files')
                ->where(static function ($w): void {
                    $w->where('is_deleted', 0)->orWhereNull('is_deleted');
                })
                ->max('created_at');

            if (is_string($createdAt) && trim($createdAt) !== '') {
                return trim($createdAt);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  callable(\Illuminate\Database\Query\Builder):void|null  $scope
     */
    private function safeCount(string $table, ?callable $scope = null): ?int
    {
        if (!$this->hasTable($table)) {
            return null;
        }

        try {
            $query = DB::connection('doc_ease')->table($table);
            if ($scope) {
                $scope($query);
            }

            return (int) $query->count();
        } catch (Throwable) {
            return null;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::connection('doc_ease')->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}

