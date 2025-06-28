import { parseFile } from 'music-metadata';

(async () => {
    try {
        const filePath = process.argv[2];
        const metadata = await parseFile(filePath);

        console.log(JSON.stringify(metadata)); // pure JSON output
    } catch (error) {
        console.error('Error parsing metadata:', error.message);
        process.exit(1);
    }
})();
