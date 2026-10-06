const fs = require('fs');
let content = fs.readFileSync('C:\\xampp\\htdocs\\game-center\\index.php', 'utf8');

// Find all corrupted emoji sequences (UTF-8 bytes showing as latin1)
const corrupted = content.match(/[\u00F0\u009F][\u0080-\u00BF]{3}/g);
console.log('Found corrupted sequences:', [...new Set(corrupted || [])].slice(0, 20));

// Replace known corrupted emoji patterns with proper emojis or remove
const replacements = {
    '\u00F0\u009F\u2022\u2018': '🎯',   // 🎯
    '\u00F0\u2022\u009F': '🎮',       // 🎮
    '\u00F0\u009F\u2018\u2018': '🎯',  // 🎯
    '\u00F0\u009F\u2018': '',          // various
    '\u00F0\u009F\u201C\u008A': '📊',  // 📊
    '\u00F0\u009F\u201C\u0088': '📈',  // 📈
    '\u00F0\u009F\u201A\u2018': '🚀',  // 🚀
    '\u00F0\u009F\u201A\u201C': '🛜',  // 🛜
};

// First, let's find all unique corrupted patterns
const patterns = new Set();
for (let i = 0; i < content.length - 3; i++) {
    const c = content.charCodeAt(i);
    if (c === 0xF0) {
        const seq = content.slice(i, i+4);
        patterns.add(seq);
    }
}
console.log('All 4-byte sequences starting with F0:', [...patterns].slice(0, 30));