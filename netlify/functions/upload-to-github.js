// netlify/functions/upload-to-github.js
const fetch = require('node-fetch');

exports.handler = async function(event, context) {
  // Проверяем метод запроса
  if (event.httpMethod !== 'POST') {
    return {
      statusCode: 405,
      body: JSON.stringify({ error: 'Method Not Allowed' })
    };
  }

  try {
    // Получаем данные из тела запроса
    const { filename, content, player } = JSON.parse(event.body);
    
    // Получаем токен из переменных окружения Netlify
    const githubToken = process.env.GITO;
    const repo = process.env.maksvesh; // формат: username/repo-name
    const branch = process.env.GITHUB_BRANCH || 'main';
    const path = process.env.GITHUB_PATH || 'game-results/';
    
    if (!githubToken || !repo) {
      return {
        statusCode: 500,
        body: JSON.stringify({ 
          error: 'GitHub configuration missing. Please set GITHUB_TOKEN and GITHUB_REPO environment variables.' 
        })
      };
    }

    // Кодируем контент в base64
    const encodedContent = Buffer.from(content).toString('base64');
    
    // Формируем URL для GitHub API
    const apiUrl = `https://api.github.com/repos/${repo}/contents/${path}${filename}`;
    
    // Создаем запрос к GitHub API
    const response = await fetch(apiUrl, {
      method: 'PUT',
      headers: {
        'Authorization': `token ${githubToken}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        message: `Game result: ${player}`,
        content: encodedContent,
        branch: branch
      })
    });

    if (!response.ok) {
      const errorData = await response.text();
      throw new Error(`GitHub API error: ${response.status} - ${errorData}`);
    }

    const result = await response.json();
    
    return {
      statusCode: 200,
      body: JSON.stringify({ 
        success: true,
        message: 'File uploaded successfully',
        url: result.content.html_url
      })
    };
    
  } catch (error) {
    console.error('Error uploading to GitHub:', error);
    
    return {
      statusCode: 500,
      body: JSON.stringify({ 
        error: error.message || 'Internal server error'
      })
    };
  }
};
