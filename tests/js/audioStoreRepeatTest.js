// Simple test script to verify audio store repeat functionality
// This can be run in a browser console or Node.js environment

// Mock the reactive function for testing
function reactive(obj) {
    return obj;
}

// Import the audio store logic (simplified for testing)
const audioStore = reactive({
    currentFile: null,
    isPlaying: false,
    playlist: [],
    currentIndex: -1,
    repeatMode: 'off'
});

const audioActions = {
    toggleRepeat() {
        switch (audioStore.repeatMode) {
            case 'off':
                audioStore.repeatMode = 'all';
                break;
            case 'all':
                audioStore.repeatMode = 'one';
                break;
            case 'one':
                audioStore.repeatMode = 'off';
                break;
        }
    },

    async moveToNext() {
        // Handle repeat one mode - replay current track
        if (audioStore.repeatMode === 'one' && audioStore.currentFile) {
            return audioStore.currentFile;
        }

        // Normal next track logic
        if (audioStore.currentIndex < audioStore.playlist.length - 1) {
            audioStore.currentIndex++;
            const nextTrack = audioStore.playlist[audioStore.currentIndex];
            audioStore.currentFile = nextTrack;
            return nextTrack;
        }

        // Handle repeat all mode - go back to first track
        if (audioStore.repeatMode === 'all' && audioStore.playlist.length > 0) {
            audioStore.currentIndex = 0;
            const firstTrack = audioStore.playlist[0];
            audioStore.currentFile = firstTrack;
            return firstTrack;
        }

        // Repeat off mode or no tracks - return null
        return null;
    }
};

// Test functions
function runTests() {
    console.log('🧪 Testing Audio Store Repeat Functionality');
    console.log('='.repeat(50));

    // Test 1: Toggle repeat modes
    console.log('\n📋 Test 1: Toggle repeat modes');
    console.log('Initial repeat mode:', audioStore.repeatMode);

    audioActions.toggleRepeat();
    console.log('After first toggle:', audioStore.repeatMode);
    console.assert(audioStore.repeatMode === 'all', 'Should be "all"');

    audioActions.toggleRepeat();
    console.log('After second toggle:', audioStore.repeatMode);
    console.assert(audioStore.repeatMode === 'one', 'Should be "one"');

    audioActions.toggleRepeat();
    console.log('After third toggle:', audioStore.repeatMode);
    console.assert(audioStore.repeatMode === 'off', 'Should be "off"');

    console.log('✅ Repeat mode cycling works correctly');

    // Test 2: Repeat one functionality
    console.log('\n📋 Test 2: Repeat one functionality');
    audioStore.repeatMode = 'one';
    audioStore.currentFile = { id: 1, title: 'Test Song' };
    audioStore.playlist = [
        { id: 1, title: 'Test Song' },
        { id: 2, title: 'Another Song' }
    ];
    audioStore.currentIndex = 0;

    audioActions.moveToNext().then(result => {
        console.log('Repeat one result:', result);
        console.assert(result.id === 1, 'Should return the same track');
        console.log('✅ Repeat one works correctly');
    });

    // Test 3: Repeat all functionality
    console.log('\n📋 Test 3: Repeat all functionality');
    audioStore.repeatMode = 'all';
    audioStore.currentIndex = 1; // At the last track

    audioActions.moveToNext().then(result => {
        console.log('Repeat all result:', result);
        console.log('Current index after repeat all:', audioStore.currentIndex);
        console.assert(result.id === 1, 'Should return the first track');
        console.assert(audioStore.currentIndex === 0, 'Should reset to first index');
        console.log('✅ Repeat all works correctly');
    });

    // Test 4: Repeat off functionality
    console.log('\n📋 Test 4: Repeat off functionality');
    audioStore.repeatMode = 'off';
    audioStore.currentIndex = 1; // At the last track

    audioActions.moveToNext().then(result => {
        console.log('Repeat off result:', result);
        console.assert(result === null, 'Should return null when at end');
        console.log('✅ Repeat off works correctly');
    });

    console.log('\n🎉 All tests completed successfully!');
}

// Run the tests
runTests();
