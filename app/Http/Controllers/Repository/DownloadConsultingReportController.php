<?php

namespace App\Http\Controllers\Repository;

use App\Consulting\MarkdownReport;
use App\Enums\ConsultingStatus;
use App\Http\Controllers\Controller;
use App\Models\Repository;
use Illuminate\Http\Response;

/**
 * „Descarcă raportul”: the newest ready „Consultanță” report as a Markdown file. Public, like the page;
 * 404 until a report is ready.
 */
class DownloadConsultingReportController extends Controller
{
    /**
     * Send the report as an attachment, e.g. consultanta-laravel-framework-abc1234.md.
     */
    public function __invoke(Repository $repository, MarkdownReport $markdown): Response
    {
        $report = $repository->consultingReports()
            ->where('status', ConsultingStatus::Ready)
            ->latest('id')
            ->firstOrFail()
            ->setRelation('repository', $repository);

        return response($markdown->render($report), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$markdown->fileName($report).'"',
        ]);
    }
}
