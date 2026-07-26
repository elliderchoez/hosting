module.exports = {
  development: {
    url: process.env.DATABASE_URL,
    dialect: process.env.DB_CONNECTION === 'mysql' ? 'mysql' : 'postgres',
    dialectOptions: process.env.DB_CONNECTION === 'mysql' ? {} : {
      ssl: false
    }
  },
  production: {
    url: process.env.DATABASE_URL,
    dialect: process.env.DB_CONNECTION === 'mysql' ? 'mysql' : 'postgres',
    dialectOptions: process.env.DB_CONNECTION === 'mysql' ? {} : {
      ssl: false
    }
  }
};
