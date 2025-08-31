<?php

require_once 'vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configure Laravel application
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Student;
use App\Models\State;
use Illuminate\Support\Facades\DB;

echo "=== DEBUGGING STATE RELATIONSHIP ===\n\n";

// Get the test student
$student = Student::where('username', 'vug/csc/16/1336')->first();

if ($student) {
    echo "Student found:\n";
    echo "ID: {$student->id}\n";
    echo "Username: {$student->username}\n";
    echo "State ID: " . ($student->state_id ?? 'NULL') . "\n";
    
    // Check if state relationship works
    if ($student->state_id) {
        $state = $student->state;
        if ($state) {
            echo "State Name: {$state->name}\n";
        } else {
            echo "State relationship failed - no state found for ID {$student->state_id}\n";
        }
    } else {
        echo "No state_id set for this student\n";
    }
    
    // List all available states
    echo "\n=== AVAILABLE STATES ===\n";
    $states = State::all();
    foreach ($states as $state) {
        echo "ID: {$state->id}, Name: {$state->name}\n";
    }
    
    // Update student with a valid state_id if needed
    if (!$student->state_id && $states->count() > 0) {
        $firstState = $states->first();
        echo "\n=== UPDATING STUDENT STATE ===\n";
        echo "Setting state_id to {$firstState->id} ({$firstState->name})\n";
        
        $student->state_id = $firstState->id;
        $student->save();
        
        echo "Student updated successfully\n";
        
        // Test the relationship again
        $student->refresh();
        $state = $student->state;
        if ($state) {
            echo "State relationship now works: {$state->name}\n";
        }
    }
} else {
    echo "Student not found\n";
}

echo "\n=== ANALYSIS COMPLETE ===\n";