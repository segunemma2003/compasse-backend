<?php

namespace App\Http\Controllers;

use App\Models\StudentResult;
use App\Models\PsychomotorAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use PDF; // Add this to composer: barryvdh/laravel-dompdf

class ReportCardController extends Controller
{
    /**
     * Get report card data (JSON format)
     */
    public function getReportCard($studentId, $termId, $academicYearId): JsonResponse
    {
        try {
            $result = StudentResult::where('student_id', $studentId)
                ->where('term_id', $termId)
                ->where('academic_year_id', $academicYearId)
                ->with([
                    'student.user',
                    'student.class',
                    'class',
                    'term',
                    'academicYear',
                    'subjectResults.subject',
                ])
                ->first();

            if (!$result) {
                return response()->json([
                    'error' => 'Result not found',
                    'message' => 'No result available for this student and term'
                ], 404);
            }

            // Get psychomotor assessment
            $psychomotor = PsychomotorAssessment::where('student_id', $studentId)
                ->where('term_id', $termId)
                ->where('academic_year_id', $academicYearId)
                ->with('assessedBy')
                ->first();

            $reportCard = [
                // Student Info
                'student' => [
                    'id' => $result->student->id,
                    'name' => $result->student->user->name ?? 'N/A',
                    'admission_number' => $result->student->admission_number,
                    'profile_picture' => $result->student->user->profile_picture ?? null,
                ],
                
                // Academic Info
                'academic' => [
                    'class' => $result->class->name ?? 'N/A',
                    'term' => $result->term->name ?? 'N/A',
                    'academic_year' => $result->academicYear->year ?? 'N/A',
                ],
                
                // Performance Summary
                'summary' => [
                    'total_score' => round($result->total_score, 2),
                    'average_score' => round($result->average_score, 2),
                    'grade' => $result->grade,
                    'position' => $result->position,
                    'out_of' => $result->out_of,
                    'class_average' => round($result->class_average ?? 0, 2),
                ],
                
                // Subject Results
                'subjects' => $result->subjectResults->map(function($sr) {
                    return [
                        'subject' => $sr->subject->name ?? 'N/A',
                        'ca_total' => round($sr->ca_total, 2),
                        'exam_score' => round($sr->exam_score, 2),
                        'total_score' => round($sr->total_score, 2),
                        'grade' => $sr->grade,
                        'position' => $sr->position,
                        'highest_score' => $sr->highest_score,
                        'lowest_score' => $sr->lowest_score,
                        'class_average' => round($sr->class_average ?? 0, 2),
                        'remark' => $sr->teacher_remark,
                    ];
                }),
                
                // Psychomotor & Affective
                'psychomotor' => $psychomotor ? [
                    'skills' => [
                        'handwriting' => $psychomotor->handwriting,
                        'drawing' => $psychomotor->drawing,
                        'sports' => $psychomotor->sports,
                        'musical_skills' => $psychomotor->musical_skills,
                        'handling_tools' => $psychomotor->handling_tools,
                        'average' => $psychomotor->getPsychomotorAverage(),
                    ],
                    'affective' => [
                        'punctuality' => $psychomotor->punctuality,
                        'neatness' => $psychomotor->neatness,
                        'politeness' => $psychomotor->politeness,
                        'honesty' => $psychomotor->honesty,
                        'relationship_with_others' => $psychomotor->relationship_with_others,
                        'self_control' => $psychomotor->self_control,
                        'attentiveness' => $psychomotor->attentiveness,
                        'perseverance' => $psychomotor->perseverance,
                        'emotional_stability' => $psychomotor->emotional_stability,
                        'average' => $psychomotor->getAffectiveAverage(),
                    ],
                    'teacher_comment' => $psychomotor->teacher_comment,
                ] : null,
                
                // Comments
                'comments' => [
                    'class_teacher' => $result->class_teacher_comment,
                    'principal' => $result->principal_comment,
                ],
                
                // Other Info
                'next_term_begins' => $result->next_term_begins,
                'status' => $result->status,
            ];

            return response()->json(['report_card' => $reportCard]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch report card',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate PDF report card
     */
    public function generatePDF(Request $request, $studentId, $termId, $academicYearId)
    {
        try {
            $result = StudentResult::where('student_id', $studentId)
                ->where('term_id', $termId)
                ->where('academic_year_id', $academicYearId)
                ->with([
                    'student.user',
                    'student.class',
                    'class',
                    'term',
                    'academicYear',
                    'subjectResults.subject',
                ])
                ->first();

            if (!$result) {
                return response()->json([
                    'error' => 'Result not found'
                ], 404);
            }

            if ($result->status !== 'published') {
                return response()->json([
                    'error' => 'Result not yet published'
                ], 400);
            }

            $psychomotor = PsychomotorAssessment::where('student_id', $studentId)
                ->where('term_id', $termId)
                ->where('academic_year_id', $academicYearId)
                ->first();

            $data = [
                'result' => $result,
                'psychomotor' => $psychomotor,
                'school' => $result->student->class->school ?? null,
            ];

            // Generate PDF (requires barryvdh/laravel-dompdf)
            // $pdf = PDF::loadView('report-card', $data);
            // return $pdf->download('report-card-' . $studentId . '.pdf');

            // For now, return JSON with message
            return response()->json([
                'message' => 'PDF generation coming soon',
                'note' => 'Install barryvdh/laravel-dompdf for PDF generation',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate PDF',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk download report cards for class
     */
    public function bulkDownload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $results = StudentResult::where('class_id', $request->class_id)
                ->where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->where('status', 'published')
                ->with('student.user')
                ->get();

            if ($results->isEmpty()) {
                return response()->json([
                    'error' => 'No published results found for this class and term'
                ], 404);
            }

            $reportCards = $results->map(function($result) use ($request) {
                return [
                    'student_id' => $result->student_id,
                    'student_name' => $result->student->user->name ?? 'N/A',
                    'admission_number' => $result->student->admission_number,
                    'download_url' => route('report-cards.pdf', [
                        'studentId' => $result->student_id,
                        'termId' => $request->term_id,
                        'academicYearId' => $request->academic_year_id,
                    ]),
                ];
            });

            return response()->json([
                'message' => 'Report cards ready for download',
                'total' => $results->count(),
                'report_cards' => $reportCards
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to prepare bulk download',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Email report card to parent/guardian
     */
    public function emailReportCard(Request $request, $studentId, $termId, $academicYearId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $result = StudentResult::where('student_id', $studentId)
                ->where('term_id', $termId)
                ->where('academic_year_id', $academicYearId)
                ->where('status', 'published')
                ->first();

            if (!$result) {
                return response()->json([
                    'error' => 'No published result found'
                ], 404);
            }

            // TODO: Implement email sending with PDF attachment
            // Mail::to($request->email)->send(new ReportCardMail($result));

            return response()->json([
                'message' => 'Email sending coming soon',
                'note' => 'Implement email service for report card delivery',
                'email' => $request->email
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to email report card',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get printable report card (HTML format)
     */
    public function getPrintableReportCard($studentId, $termId, $academicYearId): JsonResponse
    {
        try {
            // Get data same as getReportCard
            $response = $this->getReportCard($studentId, $termId, $academicYearId);
            $data = json_decode($response->getContent(), true);

            if (isset($data['error'])) {
                return $response;
            }

            // Return HTML-ready data
            return response()->json([
                'message' => 'Report card data ready for printing',
                'data' => $data['report_card'],
                'note' => 'Frontend should render this as a printable HTML template'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to prepare printable report card',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

