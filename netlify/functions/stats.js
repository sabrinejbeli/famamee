const SUPABASE_URL = process.env.SUPABASE_URL;
const SUPABASE_KEY = process.env.SUPABASE_ANON_KEY;

const headers = {
  'Access-Control-Allow-Origin': '*',
  'Content-Type': 'application/json',
  'Cache-Control': 'public, max-age=30'
};

exports.handler = async (event) => {
  if (event.httpMethod === 'OPTIONS') {
    return { statusCode: 204, headers };
  }

  try {
    const res = await fetch(
      `${SUPABASE_URL}/rest/v1/votes?select=zone_name,vote_type,created_at`,
      { headers: { 'apikey': SUPABASE_KEY, 'Authorization': `Bearer ${SUPABASE_KEY}` } }
    );
    const votes = await res.json();

    const global = { famma: 0, mafamech: 0, total: 0 };
    const zone_votes = {};

    votes.forEach(v => {
      global[v.vote_type]++;
      global.total++;
      if (!zone_votes[v.zone_name]) zone_votes[v.zone_name] = { famma: 0, mafamech: 0 };
      zone_votes[v.zone_name][v.vote_type]++;
    });

    const top_zones = Object.entries(zone_votes)
      .map(([name, counts]) => ({ name, famma: counts.famma, mafamech: counts.mafamech, total: counts.famma + counts.mafamech }))
      .sort((a, b) => b.total - a.total)
      .slice(0, 10);

    const ip_check = event.queryStringParameters && event.queryStringParameters.ip_check;
    let ip_voted = null;
    if (ip_check) {
      const ip = (event.headers['x-forwarded-for'] || '').split(',')[0].trim() || event.headers['x-real-ip'] || '0.0.0.0';
      const checkRes = await fetch(
        `${SUPABASE_URL}/rest/v1/votes?zone_name=eq.${encodeURIComponent(ip_check)}&ip_address=eq.${encodeURIComponent(ip)}&select=vote_type`,
        { headers: { 'apikey': SUPABASE_KEY, 'Authorization': `Bearer ${SUPABASE_KEY}` } }
      );
      const existing = await checkRes.json();
      ip_voted = existing.length > 0 ? existing[0].vote_type : null;
    }

    return {
      statusCode: 200, headers,
      body: JSON.stringify({ global, zone_votes, top_zones, ip_voted, last_updated: new Date().toISOString() })
    };
  } catch (err) {
    return { statusCode: 500, headers, body: JSON.stringify({ error: err.message }) };
  }
};
