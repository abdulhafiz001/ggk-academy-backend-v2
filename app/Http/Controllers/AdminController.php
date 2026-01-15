<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Student;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TeacherSubject;
use App\Models\Score;
use App\Models\Attendance;
use App\Models\AcademicSession;
use App\Models\Term;

class AdminController extends Controller
{
    /**
     * Get admin dashboard data with Redis caching
     */
    public function dashboard()
    {
        $userId = auth()->id();
        
        $data = \App\Helpers\CacheHelper::getDashboardStats('admin', $userId, function () {
            $totalStudents = Student::where('is_active', true)->count();
            $totalClasses = SchoolClass::where('is_active', true)->count();
            $totalSubjects = Subject::where('is_active', true)->count();
            $totalTeachers = User::where('role', 'teacher')->where('is_active', true)->count();
            
            $recentStudents = Student::with('schoolClass')
                                    ->where('is_active', true)
                                    ->latest()
                                    ->take(5)
                                    ->get();

            // Check for current academic session and term
            $currentSession = AcademicSession::current();
            $currentTerm = Term::current();

            return [
                'stats' => [
                    'total_students' => $totalStudents,
                    'total_classes' => $totalClasses,
                    'total_subjects' => $totalSubjects,
                    'total_teachers' => $totalTeachers,
                ],
                'recent_students' => $recentStudents,
                'academic_session' => [
                    'has_session' => $currentSession !== null,
                    'has_term' => $currentTerm !== null,
                    'session' => $currentSession,
                    'term' => $currentTerm,
                ],
            ];
        });

        return response()->json($data);
    }

    /**
     * Get all users (admin and teachers)
     */
    public function getUsers()
    {
        $users = User::where('is_active', true)->get();
        return response()->json($users);
    }

    /**
     * Create a new user (admin or teacher)
     */
    public function createUser(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'username' => 'required|string|unique:users,username',
            'role' => 'required|in:admin,teacher',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'password' => 'nullable|string|min:6',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email ?: null, // Allow null if not provided
            'username' => $request->username,
            'password' => Hash::make($request->password ?? 'password'), // Use provided password or default
            'role' => $request->role,
            'phone' => $request->phone ?: null, // Allow null if not provided
            'address' => $request->address,
            'is_active' => true,
        ]);

        // Invalidate dashboard cache (teacher count changed)
        \App\Helpers\CacheHelper::invalidateDashboard();
        
        return response()->json([
            'message' => 'User created successfully',
            'user' => $user,
        ], 201);
    }

    /**
     * Get a specific user
     */
    public function getUser(User $user)
    {
        return response()->json($user);
    }

    /**
     * Update a user
     */
    public function updateUser(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'username' => 'required|string|unique:users,username,' . $user->id,
            'role' => 'required|in:admin,teacher',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $updateData = $request->all();
        // Ensure email and phone are null if empty string
        if (isset($updateData['email']) && $updateData['email'] === '') {
            $updateData['email'] = null;
        }
        if (isset($updateData['phone']) && $updateData['phone'] === '') {
            $updateData['phone'] = null;
        }

        $user->update($updateData);

        // Invalidate dashboard cache if role changed
        \App\Helpers\CacheHelper::invalidateDashboard();

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user,
        ]);
    }

    /**
     * Get admin profile
     */
    public function getProfile(Request $request)
    {
        $user = $request->user();
        
        // Get teacher assignments if user is a teacher
        $assignments = [];
        if ($user->role === 'teacher') {
            $assignments = TeacherSubject::with(['schoolClass', 'subject'])
                                       ->where('teacher_id', $user->id)
                                       ->where('is_active', true)
                                       ->get()
                                       ->groupBy('class_id')
                                       ->map(function ($classAssignments) {
                                           $class = $classAssignments->first()->schoolClass;
                                           $class->subjects = $classAssignments->pluck('subject');
                                           return $class;
                                       })
                                       ->values();
        }

        return response()->json([
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'middle_name' => $user->middle_name,
            'email' => $user->email,
            'username' => $user->username,
            'phone' => $user->phone,
            'address' => $user->address,
            'date_of_birth' => $user->date_of_birth,
            'gender' => $user->gender,
            'qualification' => $user->qualification,
            'department' => $user->department,
            'date_joined' => $user->created_at,
            'role' => $user->role,
            'is_form_teacher' => $user->is_form_teacher,
            'avatar' => $user->avatar,
            'assignments' => $assignments,
        ]);
    }

    /**
     * Update admin profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
            'qualification' => 'nullable|string|max:500',
            'department' => 'nullable|string|max:255',
        ]);

        $user->update([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'middle_name' => $request->middle_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'date_of_birth' => $request->date_of_birth,
            'gender' => $request->gender,
            'qualification' => $request->qualification,
            'department' => $request->department,
        ]);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Change admin password
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();
        
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8',
            'confirm_password' => 'required|same:new_password',
        ]);

        // Check current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect'
            ], 400);
        }

        // Update password
        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'message' => 'Password changed successfully'
        ]);
    }

    /**
     * Delete a user
     */
    public function deleteUser(Request $request, User $user)
    {
        // Check if this is a hard delete request
        $hardDelete = $request->query('hard_delete', false);

        if ($hardDelete === 'true') {
            // Hard delete - completely remove from database
            // The boot method in User model will handle cascade deletion
            $user->delete();
            
            // Invalidate dashboard cache
            \App\Helpers\CacheHelper::invalidateDashboard();
            
            return response()->json(['message' => 'User permanently deleted successfully']);
        } else {
            // Soft delete - just deactivate
            $user->update(['is_active' => false]);
            
            // Invalidate dashboard cache
            \App\Helpers\CacheHelper::invalidateDashboard();
            
            return response()->json(['message' => 'User deactivated successfully']);
        }
    }

    /**
     * Get teacher assignments
     */
    public function getTeacherAssignments()
    {
        $assignments = TeacherSubject::with(['teacher', 'subject', 'schoolClass'])
                                   ->where('is_active', true)
                                   ->get();

        return response()->json($assignments);
    }

    /**
     * Get teacher's classes and subjects for export
     */
    public function getTeacherClassesAndSubjects(Request $request, $teacherId)
    {
        $teacher = User::find($teacherId);
        
        if (!$teacher || $teacher->role !== 'teacher') {
            return response()->json(['message' => 'Teacher not found'], 404);
        }

        $assignments = TeacherSubject::where('teacher_id', $teacherId)
            ->where('is_active', true)
            ->with(['subject', 'schoolClass'])
            ->get();

        // Group by class
        $classesMap = [];
        foreach ($assignments as $assignment) {
            $classId = $assignment->class_id;
            $class = $assignment->schoolClass;
            $subject = $assignment->subject;

            if (!isset($classesMap[$classId])) {
                $classesMap[$classId] = [
                    'id' => $class->id,
                    'name' => $class->name,
                    'subjects' => []
                ];
            }

            // Add subject if not already added
            $subjectExists = false;
            foreach ($classesMap[$classId]['subjects'] as $existingSubject) {
                if ($existingSubject['id'] === $subject->id) {
                    $subjectExists = true;
                    break;
                }
            }

            if (!$subjectExists) {
                $classesMap[$classId]['subjects'][] = [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'code' => $subject->code ?? ''
                ];
            }
        }

        return response()->json([
            'teacher' => [
                'id' => $teacher->id,
                'name' => $teacher->name
            ],
            'classes' => array_values($classesMap)
        ]);
    }

    /**
     * Assign teacher to subject and class
     */
    public function assignTeacher(Request $request)
    {
        $request->validate([
            'teacher_id' => 'required|exists:users,id',
            'subject_id' => 'required|exists:subjects,id',
            'class_id' => 'required|exists:classes,id',
        ]);

        // Check if teacher is actually a teacher
        $teacher = User::find($request->teacher_id);
        if ($teacher->role !== 'teacher') {
            return response()->json(['message' => 'Selected user is not a teacher'], 400);
        }

        // Check if assignment already exists
        $existingAssignment = TeacherSubject::where([
            'teacher_id' => $request->teacher_id,
            'subject_id' => $request->subject_id,
            'class_id' => $request->class_id,
        ])->first();

        if ($existingAssignment) {
            return response()->json(['message' => 'Teacher is already assigned to this subject and class'], 400);
        }

        $assignment = TeacherSubject::create([
            'teacher_id' => $request->teacher_id,
            'subject_id' => $request->subject_id,
            'class_id' => $request->class_id,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Teacher assigned successfully',
            'assignment' => $assignment->load(['teacher', 'subject', 'schoolClass']),
        ], 201);
    }

    /**
     * Remove teacher assignment
     */
    public function removeTeacherAssignment(TeacherSubject $assignment)
    {
        $assignment->update(['is_active' => false]);
        
        return response()->json(['message' => 'Teacher assignment removed successfully']);
    }

    /**
     * Get all students
     */
    public function getStudents()
    {
        $students = Student::with(['schoolClass', 'studentSubjects.subject'])
                          ->where('is_active', true)
                          ->get();

        return response()->json($students);
    }

    /**
     * Create a new student
     */
    public function createStudent(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'admission_number' => 'required|string|unique:students,admission_number',
            'email' => 'nullable|email|unique:students,email',
            'phone' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
            'address' => 'nullable|string',
            'parent_name' => 'nullable|string',
            'parent_phone' => 'nullable|string',
            'parent_email' => 'nullable|email',
            'class_id' => 'required|exists:classes,id',
            'subjects' => 'array',
            'subjects.*' => 'exists:subjects,id',
        ]);

        // Get current academic session and term
        $currentSession = AcademicSession::current();
        $currentTerm = Term::current();

        $student = Student::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'middle_name' => $request->middle_name,
            'admission_number' => $request->admission_number,
            'email' => $request->email,
            'phone' => $request->phone,
            'date_of_birth' => $request->date_of_birth,
            'gender' => $request->gender,
            'address' => $request->address,
            'parent_name' => $request->parent_name,
            'parent_phone' => $request->parent_phone,
            'parent_email' => $request->parent_email,
            'class_id' => $request->class_id,
            'password' => Hash::make('password'), // Hash the default password
            'is_active' => true,
            'admission_academic_session_id' => $currentSession?->id,
            'admission_term' => $currentTerm?->name,
            'status' => 'active',
        ]);

        // Create student subject relationships
        if ($request->subjects) {
            foreach ($request->subjects as $subjectId) {
                \App\Models\StudentSubject::create([
                    'student_id' => $student->id,
                    'subject_id' => $subjectId,
                    'is_active' => true,
                ]);
            }
        }

        // Record class history for the current academic session
        if ($currentSession) {
            \App\Models\StudentClassHistory::updateHistory(
                $student->id,
                $currentSession->id,
                $student->class_id
            );
        }

        // Invalidate dashboard cache
        \App\Helpers\CacheHelper::invalidateDashboard();

        return response()->json([
            'message' => 'Student created successfully',
            'student' => $student->load(['schoolClass', 'studentSubjects.subject']),
        ], 201);
    }

    /**
     * Get a specific student
     */
    public function getStudent(Student $student)
    {
        return response()->json($student->load(['schoolClass', 'studentSubjects.subject']));
    }

    /**
     * Update a student
     */
    public function updateStudent(Request $request, Student $student)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'admission_number' => 'required|string|unique:students,admission_number,' . $student->id,
            'email' => 'nullable|email|unique:students,email,' . $student->id,
            'phone' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
            'address' => 'nullable|string',
            'parent_name' => 'nullable|string',
            'parent_phone' => 'nullable|string',
            'parent_email' => 'nullable|email',
            'class_id' => 'required|exists:classes,id',
            'subjects' => 'nullable|array',
            'subjects.*' => 'string',
            'is_active' => 'boolean',
        ]);

        $student->update($request->all());

        // Update student subjects if provided
        if ($request->has('subjects')) {
            // Delete existing subject relationships completely
            $student->studentSubjects()->delete();
            
            // Create new subject relationships
            if ($request->subjects && count($request->subjects) > 0) {
                foreach ($request->subjects as $subjectName) {
                    $subject = Subject::where('name', $subjectName)->first();
                    if ($subject) {
                        \App\Models\StudentSubject::create([
                            'student_id' => $student->id,
                            'subject_id' => $subject->id,
                            'is_active' => true,
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'message' => 'Student updated successfully',
            'student' => $student->load(['schoolClass', 'studentSubjects.subject']),
        ]);
    }

    /**
     * Delete a student (soft delete)
     */
    public function deleteStudent(Student $student)
    {
        $student->delete(); // Soft delete using SoftDeletes trait
        
        // Invalidate dashboard cache
        \App\Helpers\CacheHelper::invalidateDashboard();
        
        return response()->json(['message' => 'Student deleted successfully']);
    }

    /**
     * Get recent teacher activities (scores, attendance, student additions)
     */
    public function getTeacherActivities(Request $request)
    {
        $limit = $request->get('limit', 50);
        
        $activities = [];
        
        // Get recent scores - group by teacher, class, subject, and time window (5 minutes)
        $scores = Score::with(['teacher', 'subject', 'schoolClass'])
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->limit($limit * 3) // Get more to account for grouping
            ->get();
        
        // Group scores by teacher_id + class_id + subject_id + time window (5 minutes)
        $scoreGroups = [];
        foreach ($scores as $score) {
            if (!$score->teacher_id || !$score->class_id || !$score->subject_id) {
                continue;
            }
            
            $createdAt = $score->created_at ?? $score->updated_at;
            // Round down to nearest 5 minutes for grouping
            $minute = (int)$createdAt->format('i');
            $roundedMinute = floor($minute / 5) * 5;
            $timeKey = $createdAt->format('Y-m-d H:') . str_pad($roundedMinute, 2, '0', STR_PAD_LEFT);
            
            $groupKey = $score->teacher_id . '_' . $score->class_id . '_' . $score->subject_id . '_' . $timeKey;
            
            $hasAllScores = $score->first_ca !== null && $score->second_ca !== null && $score->exam !== null;
            
            if (!isset($scoreGroups[$groupKey])) {
                $scoreGroups[$groupKey] = [
                    'count' => 1,
                    'complete_count' => $hasAllScores ? 1 : 0,
                    'teacher_id' => $score->teacher_id,
                    'teacher' => $score->teacher ? ($score->teacher->name ?? $score->teacher->first_name . ' ' . $score->teacher->last_name) : 'Unknown Teacher',
                    'subject' => $score->subject->name ?? 'Unknown Subject',
                    'class' => $score->schoolClass->name ?? 'Unknown Class',
                    'created_at' => $createdAt,
                ];
            } else {
                $scoreGroups[$groupKey]['count']++;
                if ($hasAllScores) {
                    $scoreGroups[$groupKey]['complete_count']++;
                }
            }
        }
        
        // Convert grouped scores to activities
        foreach ($scoreGroups as $groupKey => $group) {
            // If all scores in the group are complete, show "Result Entry", otherwise "Score Entry"
            $allComplete = $group['complete_count'] === $group['count'];
            $activity = $allComplete ? 'Result Entry' : 'Score Entry';
            $description = 'Recorded scores for ' . $group['count'] . ' student' . ($group['count'] > 1 ? 's' : '') . ' in ' . $group['subject'] . ' (' . $group['class'] . ')';
            
            $activities[] = [
                'id' => 'score_group_' . $groupKey,
                'type' => 'score',
                'activity' => $activity,
                'teacher_id' => $group['teacher_id'],
                'teacher' => $group['teacher'],
                'subject' => $group['subject'],
                'class' => $group['class'],
                'description' => $description,
                'status' => $allComplete ? 'completed' : 'in-progress',
                'created_at' => $group['created_at']->toDateTimeString(),
            ];
        }
        
        // Get recent attendance records - group by teacher, class, subject, date, and time window (5 minutes)
        $attendances = Attendance::with(['teacher', 'subject', 'schoolClass'])
            ->orderBy('created_at', 'desc')
            ->limit($limit * 3) // Get more to account for grouping
            ->get();
        
        // Group attendances by teacher_id + class_id + subject_id + date + time window (5 minutes)
        $attendanceGroups = [];
        foreach ($attendances as $attendance) {
            if (!$attendance->teacher_id || !$attendance->class_id || !$attendance->subject_id) {
                continue;
            }
            
            $createdAt = $attendance->created_at ?? $attendance->updated_at;
            $date = $attendance->date ? $attendance->date->format('Y-m-d') : $createdAt->format('Y-m-d');
            $timeKey = $createdAt->format('Y-m-d H:i');
            // Round down to nearest 5 minutes for grouping
            $minute = (int)$createdAt->format('i');
            $roundedMinute = floor($minute / 5) * 5;
            $timeKey = $createdAt->format('Y-m-d H:') . str_pad($roundedMinute, 2, '0', STR_PAD_LEFT);
            
            $groupKey = $attendance->teacher_id . '_' . $attendance->class_id . '_' . $attendance->subject_id . '_' . $date . '_' . $timeKey;
            
            if (!isset($attendanceGroups[$groupKey])) {
                $attendanceGroups[$groupKey] = [
                    'count' => 1,
                    'teacher_id' => $attendance->teacher_id,
                    'teacher' => $attendance->teacher ? ($attendance->teacher->name ?? $attendance->teacher->first_name . ' ' . $attendance->teacher->last_name) : 'Unknown Teacher',
                    'subject' => $attendance->subject->name ?? 'Unknown Subject',
                    'class' => $attendance->schoolClass->name ?? 'Unknown Class',
                    'created_at' => $createdAt,
                ];
            } else {
                $attendanceGroups[$groupKey]['count']++;
            }
        }
        
        // Convert grouped attendances to activities
        foreach ($attendanceGroups as $groupKey => $group) {
            $description = 'Marked attendance for ' . $group['count'] . ' student' . ($group['count'] > 1 ? 's' : '') . ' in ' . $group['subject'] . ' (' . $group['class'] . ')';
            
            $activities[] = [
                'id' => 'attendance_group_' . $groupKey,
                'type' => 'attendance',
                'activity' => 'Attendance Marking',
                'teacher_id' => $group['teacher_id'],
                'teacher' => $group['teacher'],
                'subject' => $group['subject'],
                'class' => $group['class'],
                'description' => $description,
                'status' => 'completed',
                'created_at' => $group['created_at']->toDateTimeString(),
            ];
        }
        
        // Get recent student additions (by form teachers) - these are usually individual, no grouping needed
        $students = Student::with(['schoolClass.formTeacher'])
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
        
        foreach ($students as $student) {
            // Get form teacher of the class
            $class = $student->schoolClass;
            $formTeacher = $class && $class->formTeacher ? $class->formTeacher : null;
            
            if ($formTeacher) {
                $activities[] = [
                    'id' => 'student_' . $student->id,
                    'type' => 'student',
                    'activity' => 'Student Addition',
                    'teacher_id' => $formTeacher->id,
                    'teacher' => $formTeacher->name ?? $formTeacher->first_name . ' ' . $formTeacher->last_name,
                    'subject' => 'General',
                    'class' => $class->name ?? 'Unknown Class',
                    'description' => 'Added student ' . $student->first_name . ' ' . $student->last_name . ' to ' . ($class->name ?? 'class'),
                    'status' => 'completed',
                    'created_at' => $student->created_at->toDateTimeString(),
                ];
            }
        }
        
        // Sort all activities by created_at descending
        usort($activities, function($a, $b) {
            $dateA = new \DateTime($a['created_at']);
            $dateB = new \DateTime($b['created_at']);
            return $dateB <=> $dateA;
        });
        
        // Return only the latest activities
        $activities = array_slice($activities, 0, $limit);
        
        return response()->json(['data' => $activities]);
    }
} 