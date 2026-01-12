<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Imports\StudentsImport;
use App\Imports\ScoresImport;
use App\Models\Student;
use App\Models\Score;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\AcademicSession;
use App\Models\User;
use App\Models\TeacherSubject;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ImportController extends Controller
{
    /**
     * Import students from Excel/CSV file
     */
    public function importStudents(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:10240', // 10MB max
            'class_id' => 'nullable|exists:classes,id',
        ]);

        $user = $request->user();
        
        // For teachers, class_id is required
        if ($user->role === 'teacher' && !$request->has('class_id')) {
            return response()->json([
                'message' => 'Class ID is required for teachers.',
            ], 400);
        }
        
        // If class_id is provided, verify permissions
        if ($request->has('class_id')) {
            $classId = $request->class_id;
            
            // Check if admin or form teacher of this class
            if ($user->role === 'teacher') {
                $isFormTeacher = \App\Models\SchoolClass::where('id', $classId)
                    ->where('form_teacher_id', $user->id)
                    ->where('is_active', true)
                    ->exists();
                
                if (!$isFormTeacher) {
                    return response()->json([
                        'message' => 'Unauthorized. You can only import students to classes where you are a form teacher.',
                    ], 403);
                }
            }
        }

        try {
            $import = new StudentsImport($request->class_id);
            Excel::import($import, $request->file('file'));

            $successCount = $import->getSuccessCount();
            $errors = $import->getErrors();

            return response()->json([
                'message' => 'Import completed',
                'success_count' => $successCount,
                'error_count' => count($errors),
                'errors' => $errors,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Import failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import scores from Excel/CSV file
     */
    public function importScores(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:10240',
            'class_id' => 'nullable|exists:classes,id',
            'subject_id' => 'nullable|exists:subjects,id',
        ]);

        $user = $request->user();
        $teacherId = $user->id;
        $classId = $request->class_id;
        $subjectId = $request->subject_id;

        // If class_id and subject_id are provided, validate teacher assignment
        if ($classId && $subjectId) {
            // Check if user is a teacher assigned to this class and subject
            if ($user->role === 'teacher') {
                $isAssigned = \App\Models\TeacherSubject::where('teacher_id', $teacherId)
                    ->where('class_id', $classId)
                    ->where('subject_id', $subjectId)
                    ->where('is_active', true)
                    ->exists();
                
                if (!$isAssigned) {
                    return response()->json([
                        'message' => 'Unauthorized. You are not assigned to teach this subject in this class.',
                    ], 403);
                }
            } elseif (!$user->isAdmin()) {
                return response()->json([
                    'message' => 'Unauthorized. Only teachers and admins can import scores.',
                ], 403);
            }
        }

        try {
            $import = new ScoresImport($teacherId, $classId, $subjectId);
            Excel::import($import, $request->file('file'));

            $successCount = $import->getSuccessCount();
            $errors = $import->getErrors();

            return response()->json([
                'message' => 'Import completed',
                'success_count' => $successCount,
                'error_count' => count($errors),
                'errors' => $errors,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Import failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export students to Excel/CSV
     */
    public function exportStudents(Request $request)
    {
        $request->validate([
            'class_id' => 'nullable|exists:classes,id',
            'include_subjects' => 'nullable',
        ]);

        $query = Student::with(['schoolClass', 'studentSubjects.subject'])
            ->where('is_active', true);

        if ($request->has('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        $students = $query->get();
        // Convert string "true"/"false" to boolean - handle query string values
        $includeSubjectsValue = $request->input('include_subjects', false);
        $includeSubjects = filter_var($includeSubjectsValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        // If filter_var returns null, default to false
        if ($includeSubjects === null) {
            $includeSubjects = false;
        }

        if ($includeSubjects) {
            // Export with subjects
            $data = $students->map(function ($student) {
                $subjects = $student->studentSubjects
                    ->where('is_active', true)
                    ->map(function ($studentSubject) {
                        return $studentSubject->subject->name ?? '';
                    })
                    ->filter()
                    ->implode(', ');

                return [
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'middle_name' => $student->middle_name ?? '',
                    'admission_number' => $student->admission_number,
                    'email' => $student->email ?? '',
                    'phone' => $student->phone ?? '',
                    'gender' => $student->gender ?? '',
                    'address' => $student->address ?? '',
                    'subjects' => $subjects,
                ];
            });
        } else {
            // Export basic data only
            $data = $students->map(function ($student) {
                return [
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'middle_name' => $student->middle_name ?? '',
                    'admission_number' => $student->admission_number,
                    'email' => $student->email ?? '',
                    'phone' => $student->phone ?? '',
                    'gender' => $student->gender ?? '',
                    'address' => $student->address ?? '',
                ];
            });
        }

        $filename = $includeSubjects 
            ? 'students_with_subjects_export_' . date('Y-m-d') . '.xlsx'
            : 'students_export_' . date('Y-m-d') . '.xlsx';

        $headings = $includeSubjects
            ? [
                'first_name',
                'last_name',
                'middle_name',
                'admission_number',
                'email',
                'phone',
                'gender',
                'address',
                'subjects',
            ]
            : [
                'first_name',
                'last_name',
                'middle_name',
                'admission_number',
                'email',
                'phone',
                'gender',
                'address',
            ];

        return Excel::download(new class($data, $headings) implements FromCollection, WithHeadings {
            protected $data;
            protected $headings;

            public function __construct($data, $headings)
            {
                $this->data = $data;
                $this->headings = $headings;
            }

            public function collection()
            {
                return $this->data;
            }

            public function headings(): array
            {
                return $this->headings;
            }
        }, $filename);
    }

    /**
     * Export scores/results to Excel/CSV
     */
    public function exportScores(Request $request)
    {
        $request->validate([
            'class_id' => 'nullable|exists:classes,id',
            'subject_id' => 'nullable|exists:subjects,id',
            'term' => 'nullable|in:first,second,third',
            'academic_session_id' => 'nullable|exists:academic_sessions,id',
        ]);

        $query = Score::with(['student', 'subject', 'schoolClass'])
            ->where('is_active', true);

        if ($request->has('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        if ($request->has('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->has('term')) {
            $query->where('term', $request->term);
        }

        $academicSessionId = $request->academic_session_id ?? AcademicSession::current()?->id;
        if ($academicSessionId) {
            $query->where('academic_session_id', $academicSessionId);
        }

        $scores = $query->get();

        $data = $scores->map(function ($score) {
            return [
                'Admission Number' => $score->student->admission_number ?? '',
                'Student Name' => ($score->student->first_name ?? '') . ' ' . ($score->student->last_name ?? ''),
                '1st CA' => $score->first_ca ?? '',
                '2nd CA' => $score->second_ca ?? '',
                'Exam' => $score->exam_score ?? '',
                'Remark' => $score->remark ?? '',
            ];
        });

        return Excel::download(new class($data) implements FromCollection, WithHeadings {
            protected $data;

            public function __construct($data)
            {
                $this->data = $data;
            }

            public function collection()
            {
                return $this->data;
            }

            public function headings(): array
            {
                return [
                    'Admission Number',
                    'Student Name',
                    '1st CA',
                    '2nd CA',
                    'Exam',
                    'Remark',
                ];
            }
        }, 'scores_export_' . date('Y-m-d') . '.xlsx');
    }

    /**
     * Download template for student import
     */
    public function downloadStudentTemplate(Request $request)
    {
        // Ensure user is authenticated and is admin or teacher
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        // Allow admins and teachers (form teachers can import students)
        if (!$user->isAdmin() && $user->role !== 'teacher') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $headers = [
            'Admission Number',
            'First Name',
            'Middle Name',
            'Last Name',
            'Email',
            'Phone',
            'Date of Birth',
            'Gender',
            'Parent Name',
            'Parent Phone',
            'Parent Email',
        ];

        $data = collect([$headers]);

        return Excel::download(new class($data) implements FromCollection {
            protected $data;

            public function __construct($data)
            {
                $this->data = $data;
            }

            public function collection()
            {
                return $this->data;
            }
        }, 'student_import_template.xlsx');
    }

    /**
     * Download template for score import
     */
    public function downloadScoreTemplate(Request $request)
    {
        $user = $request->user();
        
        // Check if class_id and subject_id are provided (for teacher imports)
        $classId = $request->query('class_id');
        $subjectId = $request->query('subject_id');
        
        // If class_id and subject_id are provided, validate teacher assignment and generate template with students
        if ($classId && $subjectId) {
            // Validate teacher assignment if user is a teacher
            if ($user->role === 'teacher') {
                $isAssigned = \App\Models\TeacherSubject::where('teacher_id', $user->id)
                    ->where('class_id', $classId)
                    ->where('subject_id', $subjectId)
                    ->where('is_active', true)
                    ->exists();
                
                if (!$isAssigned) {
                    return response()->json([
                        'message' => 'Unauthorized. You are not assigned to teach this subject in this class.',
                    ], 403);
                }
            } elseif (!$user->isAdmin()) {
                return response()->json([
                    'message' => 'Unauthorized. Only teachers and admins can download score templates.',
                ], 403);
            }
            
            // Get students in the class who are assigned to this subject
            $class = SchoolClass::find($classId);
            $subject = Subject::find($subjectId);
            
            if (!$class || !$subject) {
                return response()->json([
                    'message' => 'Class or subject not found.',
                ], 404);
            }
            
            // Get students in the class who are taking this subject
            $students = Student::where('class_id', $classId)
                ->where('is_active', true)
                ->whereHas('studentSubjects', function ($query) use ($subjectId) {
                    $query->where('subject_id', $subjectId)
                          ->where('is_active', true);
                })
                ->orderBy('admission_number')
                ->get();
            
            // Build template data with student rows
            $headers = [
                'Admission Number',
                'Student Name',
                'Term',
                'First CA',
                'Second CA',
                'Exam Score',
                'Remark',
            ];
            
            $data = collect([$headers]);
            
            // Add a row for each student with pre-filled admission number and name
            foreach ($students as $student) {
                $fullName = trim(($student->first_name ?? '') . ' ' . ($student->middle_name ?? '') . ' ' . ($student->last_name ?? ''));
                $data->push([
                    $student->admission_number,
                    $fullName,
                    '', // Term - to be filled by user
                    '', // First CA
                    '', // Second CA
                    '', // Exam Score
                    '', // Remark
                ]);
            }
        } else {
            // Generate generic template (for admin imports without class/subject selection)
            $headers = [
                'Admission Number',
                'Subject',
                'Class',
                'Term',
                'First CA',
                'Second CA',
                'Exam Score',
                'Remark',
            ];
            
            $data = collect([$headers]);
        }

        return Excel::download(new class($data) implements FromCollection {
            protected $data;

            public function __construct($data)
            {
                $this->data = $data;
            }

            public function collection()
            {
                return $this->data;
            }
        }, 'score_import_template.xlsx');
    }

    /**
     * Export classes to Excel/CSV
     */
    public function exportClasses(Request $request)
    {
        $classes = SchoolClass::with('formTeacher')
            ->where('is_active', true)
            ->get();

        $data = $classes->map(function ($class) {
            return [
                'Name' => $class->name,
                'Description' => $class->description ?? '',
                'Form Teacher' => $class->formTeacher->name ?? 'Not assigned',
                'Students Count' => $class->students()->where('is_active', true)->count(),
            ];
        });

        return Excel::download(new class($data) implements FromCollection, WithHeadings {
            protected $data;

            public function __construct($data)
            {
                $this->data = $data;
            }

            public function collection()
            {
                return $this->data;
            }

            public function headings(): array
            {
                return [
                    'Name',
                    'Description',
                    'Form Teacher',
                    'Students Count',
                ];
            }
        }, 'classes_export_' . date('Y-m-d') . '.xlsx');
    }

    /**
     * Export subjects to Excel/CSV
     */
    public function exportSubjects(Request $request)
    {
        $subjects = Subject::where('is_active', true)->get();

        $data = $subjects->map(function ($subject) {
            return [
                'Name' => $subject->name,
                'Code' => $subject->code,
                'Description' => $subject->description ?? '',
            ];
        });

        return Excel::download(new class($data) implements FromCollection, WithHeadings {
            protected $data;

            public function __construct($data)
            {
                $this->data = $data;
            }

            public function collection()
            {
                return $this->data;
            }

            public function headings(): array
            {
                return [
                    'Name',
                    'Code',
                    'Description',
                ];
            }
        }, 'subjects_export_' . date('Y-m-d') . '.xlsx');
    }

    /**
     * Export teachers to Excel/CSV with assigned classes, subjects, and form teacher classes
     */
    public function exportTeachers(Request $request)
    {
        $teachers = User::where('role', 'teacher')
            ->where('is_active', true)
            ->get();

        $data = $teachers->map(function ($teacher) {
            // Get assigned classes and subjects from teacher assignments
            $assignments = TeacherSubject::where('teacher_id', $teacher->id)
                ->where('is_active', true)
                ->with(['subject', 'schoolClass'])
                ->get();

            $assignedClasses = $assignments->pluck('schoolClass.name')
                ->filter()
                ->unique()
                ->implode(', ');

            $assignedSubjects = $assignments->pluck('subject.name')
                ->filter()
                ->unique()
                ->implode(', ');

            // Get form teacher classes
            $formTeacherClasses = SchoolClass::where('form_teacher_id', $teacher->id)
                ->where('is_active', true)
                ->pluck('name')
                ->implode(', ');

            return [
                'Name' => $teacher->name,
                'Username' => $teacher->username,
                'Email' => $teacher->email ?? '',
                'Phone' => $teacher->phone ?? '',
                'Assigned Classes' => $assignedClasses ?: 'None',
                'Assigned Subjects' => $assignedSubjects ?: 'None',
                'Form Teacher Classes' => $formTeacherClasses ?: 'None',
            ];
        });

        return Excel::download(new class($data) implements FromCollection, WithHeadings {
            protected $data;

            public function __construct($data)
            {
                $this->data = $data;
            }

            public function collection()
            {
                return $this->data;
            }

            public function headings(): array
            {
                return [
                    'Name',
                    'Username',
                    'Email',
                    'Phone',
                    'Assigned Classes',
                    'Assigned Subjects',
                    'Form Teacher Classes',
                ];
            }
        }, 'teachers_export_' . date('Y-m-d') . '.xlsx');
    }
}

