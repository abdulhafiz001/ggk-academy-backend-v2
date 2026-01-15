<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\ScoreController;
use App\Http\Controllers\AcademicSessionController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\GradingConfigurationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('/test', function () {
    return response()->json(['status' => 'success', 'message' => 'API is working!']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Public route to get current session/term (for initial checks)
Route::get('/academic-sessions/current', [AcademicSessionController::class, 'current']);

// Public routes with rate limiting for security
// Using custom throttle middleware for better error handling
Route::middleware(['throttle.login:5,15'])->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/student/login', [AuthController::class, 'studentLogin']);
    Route::post('/auth/teacher/login', [AuthController::class, 'login']); // Teacher login uses same endpoint as admin
});

// Student forgot password routes (public, with rate limiting)
Route::middleware(['throttle:5,15'])->group(function () {
    Route::post('/student/forgot-password/verify', [AuthController::class, 'verifyStudentIdentity']);
    Route::post('/student/forgot-password/reset', [AuthController::class, 'resetStudentPassword']);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    
    // Admin routes
    Route::middleware('admin')->group(function () {
        // User management
        Route::get('/admin/users', [AdminController::class, 'getUsers']);
        Route::post('/admin/users', [AdminController::class, 'createUser']);
        Route::get('/admin/users/{user}', [AdminController::class, 'getUser']);
        Route::put('/admin/users/{user}', [AdminController::class, 'updateUser']);
        Route::delete('/admin/users/{user}', [AdminController::class, 'deleteUser']);
        
        // Class management - Static routes MUST come before parameterized routes
        Route::get('/admin/classes', [ClassController::class, 'index']);
        Route::post('/admin/classes', [ClassController::class, 'store']);
        Route::get('/admin/classes/export', [ImportController::class, 'exportClasses']);
        // Parameterized routes come after static routes
        Route::get('/admin/classes/{class}', [ClassController::class, 'show']);
        Route::put('/admin/classes/{class}', [ClassController::class, 'update']);
        Route::delete('/admin/classes/{class}', [ClassController::class, 'destroy']);
        
        // Subject management - Static routes MUST come before parameterized routes
        Route::get('/admin/subjects', [SubjectController::class, 'index']);
        Route::post('/admin/subjects', [SubjectController::class, 'store']);
        Route::get('/admin/subjects/export', [ImportController::class, 'exportSubjects']);
        // Parameterized routes come after static routes
        Route::get('/admin/subjects/{subject}', [SubjectController::class, 'show']);
        Route::put('/admin/subjects/{subject}', [SubjectController::class, 'update']);
        Route::delete('/admin/subjects/{subject}', [SubjectController::class, 'destroy']);
        
        // Teacher assignments
        Route::get('/admin/teacher-assignments', [AdminController::class, 'getTeacherAssignments']);
        Route::get('/admin/teachers/{teacher}/classes-subjects', [AdminController::class, 'getTeacherClassesAndSubjects']);
        Route::post('/admin/teacher-assignments', [AdminController::class, 'assignTeacher']);
        Route::delete('/admin/teacher-assignments/{assignment}', [AdminController::class, 'removeTeacherAssignment']);
        Route::get('/admin/teachers/export', [ImportController::class, 'exportTeachers']);
        
        // Student management - Static routes MUST come before parameterized routes
        Route::get('/admin/students', [AdminController::class, 'getStudents']);
        Route::post('/admin/students', [AdminController::class, 'createStudent']);
        // Import/Export routes MUST come before {student} route
        Route::post('/admin/students/import', [ImportController::class, 'importStudents']);
        Route::get('/admin/students/export', [ImportController::class, 'exportStudents']);
        Route::get('/admin/students/import-template', [ImportController::class, 'downloadStudentTemplate']);
        // Parameterized routes come after static routes
        Route::get('/admin/students/{student}', [AdminController::class, 'getStudent']);
        Route::put('/admin/students/{student}', [AdminController::class, 'updateStudent']);
        Route::delete('/admin/students/{student}', [AdminController::class, 'deleteStudent']);
        
        // Score management
        Route::get('/admin/scores', [ScoreController::class, 'adminIndex']);
        Route::get('/admin/students/{student}/results', [ScoreController::class, 'adminStudentResults']);
        Route::get('/admin/classes/{class}/results', [ScoreController::class, 'getClassResults']);
        
        // Dashboard data
        Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
        
        // Profile management
        Route::get('/admin/profile', [AdminController::class, 'getProfile']);
        Route::put('/admin/profile', [AdminController::class, 'updateProfile']);
        Route::put('/admin/change-password', [AdminController::class, 'changePassword']);
        
        // Academic Session management
        Route::get('/admin/academic-sessions', [AcademicSessionController::class, 'index']);
        Route::post('/admin/academic-sessions', [AcademicSessionController::class, 'store']);
        Route::get('/admin/academic-sessions/current', [AcademicSessionController::class, 'current']);
        Route::get('/admin/academic-sessions/{academicSession}', [AcademicSessionController::class, 'show']);
        Route::put('/admin/academic-sessions/{academicSession}', [AcademicSessionController::class, 'update']);
        Route::delete('/admin/academic-sessions/{academicSession}', [AcademicSessionController::class, 'destroy']);
        Route::post('/admin/academic-sessions/{academicSession}/set-current', [AcademicSessionController::class, 'setCurrent']);
        Route::put('/admin/terms/{term}', [AcademicSessionController::class, 'updateTerm']);
        Route::post('/admin/terms/{term}/set-current', [AcademicSessionController::class, 'setCurrentTerm']);
        
        // Promotion Management
        Route::get('/admin/promotion-rules', [PromotionController::class, 'index']);
        Route::post('/admin/promotion-rules', [PromotionController::class, 'store']);
        Route::put('/admin/promotion-rules/{promotionRule}', [PromotionController::class, 'update']);
        Route::delete('/admin/promotion-rules/{promotionRule}', [PromotionController::class, 'destroy']);
        Route::post('/admin/promote-students', [PromotionController::class, 'promoteStudents']);
        
        // Report Generation
        Route::get('/admin/students/{student}/report-card', [ReportController::class, 'generateStudentReport']);
        Route::get('/admin/classes/{class}/report', [ReportController::class, 'generateClassReport']);
        Route::post('/admin/scores/import', [ImportController::class, 'importScores']);
        Route::get('/admin/scores/export', [ImportController::class, 'exportScores']);
        Route::get('/admin/scores/import-template', [ImportController::class, 'downloadScoreTemplate']);
        
        // Attendance Analysis
        Route::get('/admin/attendance/statistics', [AttendanceController::class, 'getAttendanceStatistics']);
        Route::get('/admin/attendance/records', [AttendanceController::class, 'getAttendanceRecords']);
        
        // Teacher Activities
        Route::get('/admin/teacher-activities', [AdminController::class, 'getTeacherActivities']);
        
        // Grading Configuration
        Route::get('/admin/grading-configurations', [GradingConfigurationController::class, 'index']);
        Route::post('/admin/grading-configurations', [GradingConfigurationController::class, 'store']);
        Route::get('/admin/grading-configurations/{gradingConfiguration}', [GradingConfigurationController::class, 'show']);
        Route::put('/admin/grading-configurations/{gradingConfiguration}', [GradingConfigurationController::class, 'update']);
        Route::delete('/admin/grading-configurations/{gradingConfiguration}', [GradingConfigurationController::class, 'destroy']);
        Route::post('/admin/grading-configurations/{gradingConfiguration}/set-default', [GradingConfigurationController::class, 'setDefault']);
    });
    
    // Teacher routes
    Route::middleware('teacher')->group(function () {
        Route::get('/teacher/assignments', [TeacherController::class, 'getAssignments']);
        Route::get('/teacher/classes', [TeacherController::class, 'getClasses']);
        Route::get('/teacher/form-teacher-classes', [TeacherController::class, 'getFormTeacherClasses']);
        Route::get('/teacher/subjects', [TeacherController::class, 'getSubjects']);
        Route::get('/teacher/subjects/all', [TeacherController::class, 'getAllSubjects']);
        Route::get('/teacher/students', [TeacherController::class, 'getStudents']);
        Route::post('/teacher/students', [TeacherController::class, 'addStudent']);
        Route::put('/teacher/students/{student}', [TeacherController::class, 'updateStudent']);
        Route::delete('/teacher/students/{student}', [TeacherController::class, 'deleteStudent']);
        Route::get('/teacher/dashboard', [TeacherController::class, 'dashboard']);
        
        // Profile management
        Route::get('/teacher/profile', [TeacherController::class, 'getProfile']);
        Route::put('/teacher/profile', [TeacherController::class, 'updateProfile']);
        Route::put('/teacher/change-password', [TeacherController::class, 'changePassword']);
        
        // Score management
        Route::get('/teacher/scores', [ScoreController::class, 'teacherIndex']);
        Route::get('/teacher/scores/assignments', [ScoreController::class, 'getTeacherAssignmentsForScores']);
        Route::get('/teacher/scores/students', [ScoreController::class, 'getStudentsForClassSubject']);
        Route::get('/teacher/scores/existing', [ScoreController::class, 'getExistingScores']);
        Route::get('/teacher/scores/subject', [ScoreController::class, 'getSubjectScores']);
        Route::post('/teacher/scores', [ScoreController::class, 'store']);
        Route::put('/teacher/scores/{score}', [ScoreController::class, 'update']);
        
        // Teacher Import/Export
        Route::post('/teacher/scores/import', [ImportController::class, 'importScores']);
        Route::get('/teacher/scores/export', [ImportController::class, 'exportScores']);
        Route::get('/teacher/scores/import-template', [ImportController::class, 'downloadScoreTemplate']);
        
        // Student Import/Export (for form teachers)
        Route::post('/teacher/students/import', [ImportController::class, 'importStudents']);
        Route::get('/teacher/students/import-template', [ImportController::class, 'downloadStudentTemplate']);
        
        // Student scores
        Route::get('/teacher/students/{student}/scores', [ScoreController::class, 'getStudentScores']);
        
        // Student results (for form teachers)
        Route::get('/teacher/students/{student}/results', [ScoreController::class, 'teacherStudentResults']);
        
        // Student results page (for form teachers)
        Route::get('/teacher/student-results/{student}', [ScoreController::class, 'teacherStudentResults']);
        
        // Class results (for form teachers)
        Route::get('/teacher/classes/{class}/results', [ScoreController::class, 'getClassResults']);
        
        // Check form teacher status
        Route::get('/teacher/form-teacher-status', [TeacherController::class, 'checkFormTeacherStatus']);
        
        // Attendance Management
        Route::get('/teacher/attendance/classes', [AttendanceController::class, 'getTeacherClasses']);
        Route::get('/teacher/attendance/students', [AttendanceController::class, 'getClassStudents']);
        Route::post('/teacher/attendance/mark', [AttendanceController::class, 'markAttendance']);
        Route::get('/teacher/attendance/records', [AttendanceController::class, 'getAttendanceRecords']);
    });
    
    // Form Teacher routes (can access some admin endpoints with restrictions)
    Route::middleware(['teacher', 'form.teacher'])->group(function () {
        Route::get('/form-teacher/scores', [ScoreController::class, 'adminIndex']);
        Route::get('/form-teacher/classes', [ClassController::class, 'index']);
        Route::get('/form-teacher/classes/{class}', [ClassController::class, 'show']);
        Route::get('/form-teacher/classes/{class}/results', [ScoreController::class, 'getClassResults']);
        Route::get('/form-teacher/debug', [ClassController::class, 'debugFormTeacher']);
    });
    
    // Student routes
    Route::middleware('student')->group(function () {
        Route::get('/student/profile', [StudentController::class, 'getProfile']);
        Route::put('/student/profile', [StudentController::class, 'updateProfile']);
        Route::put('/student/change-password', [StudentController::class, 'changePassword']);
        Route::get('/student/results', [StudentController::class, 'getResults']);
        Route::get('/student/subjects', [StudentController::class, 'getSubjects']);
        Route::get('/student/dashboard', [StudentController::class, 'dashboard']);
        
        // Student report card (uses authenticated student)
        Route::get('/student/report-card', [ReportController::class, 'generateStudentReport']);
    });
}); 