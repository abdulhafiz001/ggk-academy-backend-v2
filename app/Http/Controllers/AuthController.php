<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Models\Student;

class AuthController extends Controller
{
    /**
     * Login for admin and teachers
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)
                   ->where('is_active', true)
                   ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'role' => $user->role,
        ]);
    }

    /**
     * Login for students
     * Rate limiting is handled by throttle:login middleware
     */
    public function studentLogin(Request $request)
    {
        try {
            $request->validate([
                'admission_number' => 'required|string',
                'password' => 'required|string',
            ]);

            $student = Student::where('admission_number', $request->admission_number)
                             ->where('is_active', true)
                             ->first();

            if (!$student) {
                throw ValidationException::withMessages([
                    'admission_number' => ['The provided credentials are incorrect.'],
                ]);
            }

            // Verify password
            // Handle both hashed and plain text passwords (for backward compatibility)
            $passwordValid = false;
            $storedPassword = $student->getAttributes()['password'] ?? $student->password;
            
            if ($storedPassword) {
                // Check if stored password is a bcrypt hash
                $isHashed = strlen($storedPassword) === 60 && 
                           (strpos($storedPassword, '$2y$') === 0 || strpos($storedPassword, '$2a$') === 0 || strpos($storedPassword, '$2b$') === 0);
                
                if ($isHashed) {
                    // Verify against hashed password
                    $passwordValid = Hash::check($request->password, $storedPassword);
                } else {
                    // Plain text comparison (for legacy records)
                    $passwordValid = $storedPassword === $request->password;
                    
                    // If valid and plain text, upgrade to hashed
                    if ($passwordValid) {
                        $student->password = $request->password; // Mutator will hash it
                        $student->save();
                    }
                }
            }

            if (!$passwordValid) {
                throw ValidationException::withMessages([
                    'admission_number' => ['The provided credentials are incorrect.'],
                ]);
            }

            // Create token
            try {
                $token = $student->createToken('student-token')->plainTextToken;
            } catch (\Exception $e) {
                \Log::error('Token creation failed', [
                    'student_id' => $student->id,
                    'error' => $e->getMessage()
                ]);
                throw new \Exception('Failed to create authentication token. Please contact administrator.');
            }

            // Load relationships safely - handle cases where class_id might be null
            try {
                if ($student->class_id) {
                    $student->load([
                        'schoolClass' => function ($query) {
                            $query->with('formTeacher');
                        }
                    ]);
                }
                
                $student->load('studentSubjects.subject');
            } catch (\Exception $e) {
                // Log but don't fail - relationships are optional for login
                \Log::warning('Failed to load some student relationships', [
                    'student_id' => $student->id,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'student' => $student,
                'token' => $token,
                'role' => 'student',
            ]);
            
        } catch (ValidationException $e) {
            // Re-throw validation exceptions as-is
            throw $e;
        } catch (\Exception $e) {
            // Log the actual error for debugging
            \Log::error('Student login error', [
                'admission_number' => $request->admission_number ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return a generic error message to the client
            return response()->json([
                'message' => 'An error occurred during login. Please try again later.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request)
    {
        $user = $request->user();
        
        if ($user instanceof Student) {
            return response()->json([
                'user' => $user->load(['schoolClass.formTeacher']),
                'role' => 'student',
            ]);
        }

        return response()->json([
            'user' => $user,
            'role' => $user->role,
        ]);
    }

    /**
     * Verify student identity for password reset
     */
    public function verifyStudentIdentity(Request $request)
    {
        $request->validate([
            'admission_number_or_email' => 'required|string',
        ]);

        $identifier = $request->admission_number_or_email;
        
        // Try to find student by admission number or email
        $student = Student::where(function($query) use ($identifier) {
            $query->where('admission_number', $identifier)
                  ->orWhere('email', $identifier);
        })
        ->where('is_active', true)
        ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student not found. Please check your admission number or email.',
            ], 404);
        }

        // Return minimal student info (no sensitive data)
        return response()->json([
            'message' => 'Student identity verified',
            'data' => [
                'id' => $student->id,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'admission_number' => $student->admission_number,
            ],
        ]);
    }

    /**
     * Reset student password
     */
    public function resetStudentPassword(Request $request)
    {
        $request->validate([
            'admission_number_or_email' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $identifier = $request->admission_number_or_email;
        
        // Find student by admission number or email
        $student = Student::where(function($query) use ($identifier) {
            $query->where('admission_number', $identifier)
                  ->orWhere('email', $identifier);
        })
        ->where('is_active', true)
        ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student not found.',
            ], 404);
        }

        // Update password
        $student->password = Hash::make($request->password);
        $student->save();

        return response()->json([
            'message' => 'Password reset successfully. You can now login with your new password.',
        ]);
    }
} 