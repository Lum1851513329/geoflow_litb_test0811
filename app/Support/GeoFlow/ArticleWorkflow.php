<?php

namespace App\Support\GeoFlow;

use App\Models\Article;
use Illuminate\Support\Str;

final class ArticleWorkflow
{
    public static function normalizeState(string $status, string $reviewStatus, ?string $publishedAt = null): array
    {
        $allowedStatuses = ['draft', 'published', 'private'];
        $allowedReviewStatuses = ['pending', 'approved', 'rejected', 'auto_approved'];

        if (! in_array($status, $allowedStatuses, true)) {
            $status = 'draft';
        }

        if (! in_array($reviewStatus, $allowedReviewStatuses, true)) {
            $reviewStatus = 'pending';
        }

        if (in_array($reviewStatus, ['pending', 'rejected'], true)) {
            $status = 'draft';
        }

        if ($status === 'published' && in_array($reviewStatus, ['pending', 'rejected'], true)) {
            $reviewStatus = 'approved';
        }

        if ($status !== 'published' && $reviewStatus === 'auto_approved') {
            $status = 'published';
        }

        if ($status === 'published' && $reviewStatus === 'pending') {
            $reviewStatus = 'approved';
        }

        if ($status === 'published') {
            $publishedAt = $publishedAt ?: date('Y-m-d H:i:s');
        } else {
            $publishedAt = null;
        }

        return [
            'status' => $status,
            'review_status' => $reviewStatus,
            'published_at' => $publishedAt,
        ];
    }

    /**
     * 之前这里完全没用到 $title 参数，永远只返回一个 8 位随机字符串——
     * 五个调用方都传了真实标题进来，最终却全部落到 kyo84pen 这类和标题
     * 毫无关系的 URL 上。改成从标题派生 slug，冲突时按 WordPress 的
     * 惯例加 -2/-3 后缀，而不是整段重新随机。
     */
    public static function generateUniqueSlug(string $title, ?int $excludeArticleId = null): string
    {
        $base = trim(Str::slug($title), '-');
        if ($base === '') {
            // Str::slug() drops non-Latin text (e.g. Chinese-only titles)
            // entirely, leaving nothing meaningful to put in the URL —
            // keep the old opaque-but-safe fallback for that case only.
            return self::uniqueRandomSlug($excludeArticleId);
        }
        $base = Str::limit($base, 80, '');
        $base = rtrim($base, '-');

        $slug = $base;
        $suffix = 2;

        try {
            while (self::slugExists($slug, $excludeArticleId)) {
                if ($suffix > 50) {
                    // Pathological run of collisions on the same base; fall
                    // back rather than loop indefinitely.
                    return self::uniqueRandomSlug($excludeArticleId);
                }
                $slug = $base.'-'.$suffix;
                $suffix++;
            }

            return $slug;
        } catch (\Throwable) {
            return self::randomSlug(8);
        }
    }

    private static function slugExists(string $slug, ?int $excludeArticleId): bool
    {
        $q = Article::withTrashed()->where('slug', $slug);
        if ($excludeArticleId !== null) {
            $q->where('id', '!=', $excludeArticleId);
        }

        return $q->exists();
    }

    private static function uniqueRandomSlug(?int $excludeArticleId): string
    {
        $slug = self::randomSlug(8);
        try {
            while (self::slugExists($slug, $excludeArticleId)) {
                $slug = self::randomSlug(8);
            }
        } catch (\Throwable) {
            // Can't verify uniqueness against a broken connection; return
            // as-is like the pre-existing behavior did.
        }

        return $slug;
    }

    private static function randomSlug(int $length): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $slug;
    }
}
